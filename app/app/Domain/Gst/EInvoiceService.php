<?php

declare(strict_types=1);

namespace App\Domain\Gst;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gst\EInvoiceEvent;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reports posted invoices to the e-invoice system and creates e-way bills through the configured
 * provider. The provider is called outside any database transaction (no locks held over the
 * network); every request and answer — including failures — is kept as an append-only event, and
 * the IRN, QR and e-way bill number are copied onto the invoice.
 */
final class EInvoiceService
{
    public const CANCEL_WINDOW_HOURS = 24;

    /** IRP cancellation reason codes. */
    public const CANCEL_REASONS = ['1' => 'Duplicate', '2' => 'Data entry mistake', '3' => 'Order cancelled', '4' => 'Others'];

    public function __construct(
        private readonly EInvoiceProvider $provider,
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
    ) {}

    public function required(SalesBill $bill): bool
    {
        return (bool) config('ricemill.gst.einvoice_enabled') && $bill->buyer_gstin !== null;
    }

    public function ewayRequired(SalesBill $bill): bool
    {
        return $bill->total_paise > (int) config('ricemill.gst.eway_threshold_paise');
    }

    public function generate(SalesBill $bill): SalesBill
    {
        $this->require();
        $bill = $bill->fresh() ?? $bill;
        if ($bill->irn !== null && $bill->einvoice_status === 'generated') {
            return $bill; // idempotent
        }
        $payload = EInvoicePayload::invoice($bill, $this->seller($bill), Party::query()->findOrFail($bill->party_id));
        $result = $this->call($bill, 'irn_generated', $payload, fn () => $this->provider->generateIrn($payload));

        return DB::transaction(function () use ($bill, $result): SalesBill {
            $bill = SalesBill::query()->lockForUpdate()->findOrFail($bill->id);
            $bill->forceFill([
                'irn' => $result['irn'], 'irn_ack_no' => $result['ack_no'], 'irn_ack_at' => CarbonImmutable::parse($result['ack_at']),
                'irn_signed_qr' => $result['signed_qr'], 'einvoice_status' => 'generated',
            ])->save();

            return $bill;
        });
    }

    public function cancel(SalesBill $bill, string $reasonCode, string $remark): SalesBill
    {
        $this->require();
        if (! isset(self::CANCEL_REASONS[$reasonCode]) || mb_strlen(trim($remark)) < 5) {
            throw new BusinessRuleViolation('Choose a cancellation reason and write a remark.');
        }
        $bill = $bill->fresh() ?? $bill;
        if ($bill->einvoice_status !== 'generated' || $bill->irn === null) {
            throw new BusinessRuleViolation('This invoice has no active IRN.');
        }
        if ($bill->irn_ack_at !== null && $bill->irn_ack_at->diffInHours(now(), true) > self::CANCEL_WINDOW_HOURS) {
            throw new BusinessRuleViolation('An IRN can be cancelled only within 24 hours; issue a credit note instead.');
        }
        if ($bill->eway_bill_no !== null) {
            throw new BusinessRuleViolation('Cancel the e-way bill on the portal before the IRN.');
        }
        $irn = (string) $bill->irn;
        $this->call($bill, 'irn_cancelled', ['Irn' => $irn, 'CnlRsn' => $reasonCode, 'CnlRem' => $remark],
            fn () => $this->provider->cancelIrn($irn, $reasonCode, $remark));
        $bill->forceFill(['einvoice_status' => 'cancelled'])->save();

        return $bill;
    }

    public function ewayBill(SalesBill $bill, int $distanceKm): SalesBill
    {
        $this->require();
        $bill = $bill->fresh() ?? $bill;
        if ($bill->eway_bill_no !== null) {
            return $bill;
        }
        $payload = EInvoicePayload::ewayBill($bill, GateEntry::query()->find($bill->gate_entry_id), $distanceKm);
        $result = $this->call($bill, 'ewb_generated', $payload, fn () => $this->provider->generateEwayBill($payload));
        $bill->forceFill(['eway_bill_no' => $result['eway_bill_no'], 'eway_valid_until' => CarbonImmutable::parse($result['valid_until'])])->save();

        return $bill;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  callable(): array<string, mixed>  $send
     * @return array<string, mixed>
     */
    private function call(SalesBill $bill, string $kind, array $request, callable $send): array
    {
        try {
            $response = $send();
        } catch (Throwable $e) {
            $this->event($bill, 'failed', $request, ['kind' => $kind, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw new BusinessRuleViolation('The e-invoice system did not accept the request: '.$e->getMessage());
        }
        $this->event($bill, $kind, $request, $response);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     */
    private function event(SalesBill $bill, string $kind, array $request, ?array $response): void
    {
        EInvoiceEvent::query()->create([
            'entity_id' => $bill->entity_id, 'sales_bill_id' => $bill->id, 'kind' => $kind, 'provider' => $this->provider->name(),
            'request' => $request, 'response' => $response, 'actor_login_account_id' => $this->actor->accountId(), 'created_at' => now(),
        ]);
    }

    private function seller(SalesBill $bill): Entity
    {
        return $this->tenant->runAsSystem(fn () => Entity::query()->findOrFail($bill->entity_id));
    }

    private function require(): void
    {
        if (! ($this->actor->account()?->hasPermission('ledger.post') ?? false)) {
            throw new BusinessRuleViolation("You need 'ledger.post' for this.");
        }
    }
}
