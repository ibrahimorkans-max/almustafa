<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GatePass;
use App\Models\Gate\GatePassCancellation;
use App\Models\Masters\Party;
use Illuminate\Support\Facades\DB;

/**
 * Gate passes (Gate & weighbridge → Gate passes): party, vehicle, number of bags and weight per bag; the total is
 * worked out. Each gets the next number of the mill's gate pass series (shared with the passes of sales
 * dispatches, so no two passes ever have the same number) and a verification code over its figures, printed with
 * a QR code to the public check page. A sale pass prints with the mill's logo for selling, any other with its logo.
 */
final class GatePasses
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly PdfRenderer $pdf,
        private readonly AuditLogger $audit,
    ) {}

    public static function canIssue(): bool
    {
        $me = app(ActorContext::class)->account();

        return $me !== null && ($me->hasPermission('gatepass.issue') || $me->hasPermission('gate.entry'));
    }

    /**
     * @param  array{party_id?: string|null, party_name?: string|null, vehicle_no: string, bag_count: int|string, kg_per_bag: string|int|float,
     *               goods?: string|null, purpose?: string|null, remarks?: string|null, gate_entry_id?: string|null}  $data
     */
    public function issue(array $data): GatePass
    {
        if (! self::canIssue()) {
            throw new BusinessRuleViolation('Issuing gate passes needs the gate or gate pass right.');
        }
        $party = filled($data['party_id'] ?? null) ? Party::query()->find($data['party_id']) : null;
        $name = trim((string) ($data['party_name'] ?? '')) ?: (string) $party?->name;
        $vehicle = strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $data['vehicle_no'])));
        $bags = (int) $data['bag_count'];
        $perBag = trim((string) $data['kg_per_bag']);
        if ($name === '') {
            throw new BusinessRuleViolation('Give the party\'s name.');
        }
        if ($vehicle === '' || mb_strlen($vehicle) > 30) {
            throw new BusinessRuleViolation('Give the vehicle number.');
        }
        if ($bags < 1 || $bags > 100000) {
            throw new BusinessRuleViolation('The number of bags must be 1 or more.');
        }
        if (preg_match('/^\d{1,6}(\.\d{1,3})?$/', $perBag) !== 1 || bccomp($perBag, '0', 3) <= 0) {
            throw new BusinessRuleViolation('The weight per bag must be more than 0 kg (e.g. 50 or 25.5).');
        }
        $entry = filled($data['gate_entry_id'] ?? null) ? GateEntry::query()->find($data['gate_entry_id']) : null;

        return DB::transaction(function () use ($data, $party, $name, $vehicle, $bags, $perBag, $entry): GatePass {
            $pass = GatePass::query()->create([
                'serial' => $this->numbers->next(DocumentNumbers::GATE_PASS),
                'party_id' => $party?->id, 'party_name' => mb_substr($name, 0, 160), 'vehicle_no' => $vehicle, 'gate_entry_id' => $entry?->id,
                'bag_count' => $bags, 'kg_per_bag' => $perBag, 'total_kg' => bcmul((string) $bags, $perBag, 3),
                'goods' => filled($data['goods'] ?? null) ? mb_substr(trim((string) $data['goods']), 0, 160) : null,
                'purpose' => ($data['purpose'] ?? 'other') === 'sale' ? 'sale' : 'other',
                'remarks' => filled($data['remarks'] ?? null) ? mb_substr(trim((string) $data['remarks']), 0, 300) : null,
                'created_by' => $this->actor->accountId(), 'created_at' => now(),
            ]);
            $this->audit->record('gate_pass.issued', $pass, null, null, ['serial' => $pass->serial, 'vehicle' => $vehicle, 'bags' => $bags]);

            return $pass;
        });
    }

    public function cancel(GatePass $pass, string $reason): GatePassCancellation
    {
        $me = $this->actor->account();
        if ($me === null || ! $me->hasPermission('gatepass.issue')) {
            throw new BusinessRuleViolation('Cancelling a gate pass needs the gate pass right (a supervisor).');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for cancelling.');
        }
        if (GatePassCancellation::query()->where('gate_pass_id', $pass->id)->exists()) {
            throw new BusinessRuleViolation('This gate pass is already cancelled.');
        }
        $cancel = GatePassCancellation::query()->create(['gate_pass_id' => $pass->id, 'reason' => mb_substr(trim($reason), 0, 300),
            'created_by' => $this->actor->accountId(), 'created_at' => now()]);
        $this->audit->record('gate_pass.cancelled', $pass, null, null, ['serial' => $pass->serial, 'reason' => $cancel->reason]);

        return $cancel;
    }

    /** The code printed on the pass: genuine only with these exact figures. */
    public static function code(GatePass $pass): string
    {
        return SlipRenderer::verificationCode($pass->id, $pass->snapshot());
    }

    public function pdf(GatePass $pass): string
    {
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($pass->entity_id));

        return $this->pdf->render('gate.gate-pass', [
            'title' => 'Gate pass '.$pass->serial, 'pass' => $pass->loadMissing(['party', 'cancellation']),
        ] + ($pass->purpose === 'sale' ? ['brand_use' => 'sales'] : []), $entity, 'A5', $pass->serial, self::code($pass));
    }
}
