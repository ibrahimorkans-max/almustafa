<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Accounts\PeriodLocks;
use App\Domain\Claims\ClaimService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\PurchaseBill;
use App\Models\Accounts\Voucher;
use App\Models\Claims\Claim;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use Carbon\CarbonImmutable;

/**
 * Monthly registers for the sales-tax return (FBR IRIS, Annex-C: sales) and the withholding statement
 * (s. 165: tax deducted u/s 153). Computed only from posted invoices, posted credit notes and posted
 * purchase bills; amounts in paisa. The CSV columns follow the IRIS upload order so the accountant can
 * paste them; FBR changes these templates from time to time, so they are checked before filing.
 */
final class SalesTaxReturns
{
    public function __construct(private readonly PeriodLocks $periods) {}

    /**
     * @return array{period: string, locked: bool, sales: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function salesRegister(string $period): array
    {
        [$from, $to] = self::range($period);
        $rows = [];
        $bills = SalesBill::query()->with('party')->where('status', SalesBill::POSTED)
            ->whereBetween('bill_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('bill_date')->orderBy('number')->get();
        foreach ($bills as $bill) {
            $rows[] = self::row($bill, 'SI', (string) $bill->number, $bill->bill_date->toDateString(), 1, [
                'taxable' => $bill->taxable_paise, 'sales_tax' => $bill->sales_tax_paise, 'further_tax' => $bill->further_tax_paise,
            ], null);
        }
        foreach ($this->creditNotes($from, $to) as $note) {
            $rows[] = $note;
        }

        $summary = ['taxable' => 0, 'exempt' => 0, 'sales_tax' => 0, 'further_tax' => 0, 'value' => 0];
        foreach ($rows as $r) {
            $summary[$r['exempt'] || $r['rate_bp'] === 0 ? 'exempt' : 'taxable'] += $r['taxable'];
            $summary['sales_tax'] += $r['sales_tax'];
            $summary['further_tax'] += $r['further_tax'];
            $summary['value'] += $r['taxable'] + $r['sales_tax'] + $r['further_tax'];
        }

        return ['period' => $period, 'locked' => $this->periods->isLocked($from), 'sales' => $rows, 'summary' => $summary];
    }

    /**
     * Income tax withheld u/s 153(1)(a) on purchases posted in the month.
     *
     * @return list<array{supplier: string, ntn: string|null, cnic: string|null, filer: bool, bill: string, date: string, gross: int, base: int, rate_bp: int, tax: int}>
     */
    public function withholdingRegister(string $period): array
    {
        [$from, $to] = self::range($period);
        $rows = [];
        $bills = PurchaseBill::query()->where('status', PurchaseBill::POSTED)->where('wht_paise', '>', 0)
            ->whereBetween('bill_date', [$from->toDateString(), $to->toDateString()])->orderBy('bill_date')->get();
        foreach ($bills as $bill) {
            /** @var Party $party */
            $party = Party::query()->withArchived()->findOrFail($bill->party_id);
            $rows[] = [
                'supplier' => $party->legal_name ?? $party->name, 'ntn' => $party->ntn, 'cnic' => $party->cnic, 'filer' => $bill->wht_filer,
                'bill' => (string) ($bill->snapshot['gate_entry_number'] ?? $bill->id), 'date' => $bill->bill_date->toDateString(),
                'gross' => $bill->gross_paise - $bill->rate_cut_paise, 'base' => $bill->wht_base_paise, 'rate_bp' => $bill->wht_rate_bp, 'tax' => $bill->wht_paise,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function creditNotes(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $notes = [];
        $claims = Claim::query()->where('document_type', Claim::SALES)->where('status', Claim::SETTLED)->whereNotNull('voucher_id')->get();
        foreach ($claims as $claim) {
            $voucher = Voucher::query()->find($claim->voucher_id);
            if ($voucher === null || $voucher->voucher_date->lt($from) || $voucher->voucher_date->gt($to)) {
                continue;
            }
            $bill = SalesBill::query()->with('party')->find($claim->document_id);
            if ($bill === null) {
                continue;
            }
            $split = ClaimService::split((int) $claim->settled_paise, $bill);
            $notes[] = self::row($bill, 'CN', (string) $voucher->number, $voucher->voucher_date->toDateString(), -1, [
                'taxable' => $split['taxable'], 'sales_tax' => $split['sales_tax'], 'further_tax' => $split['further_tax'],
            ], (string) $bill->number);
        }

        return $notes;
    }

    /**
     * @param  array{taxable: int, sales_tax: int, further_tax: int}  $amounts
     * @return array<string, mixed>
     */
    private static function row(SalesBill $bill, string $type, string $number, string $date, int $sign, array $amounts, ?string $against): array
    {
        return [
            'buyer_ntn' => $bill->buyer_ntn, 'buyer_name' => (string) ($bill->snapshot['buyer']['name'] ?? $bill->party->name),
            'buyer_type' => $bill->buyer_strn !== null ? 'Registered' : 'Unregistered', 'province' => $bill->snapshot['buyer']['province'] ?? null,
            'type' => $type, 'number' => $number, 'date' => $date, 'against' => $against,
            'pct' => $bill->pct_code, 'description' => (string) ($bill->snapshot['product']['name'] ?? ''),
            'quantity_kg' => $sign < 0 ? '0.000' : (string) $bill->quantity_kg, 'rate_bp' => $bill->tax_rate_bp, 'exempt' => $bill->tax_exempt,
            'taxable' => $sign * $amounts['taxable'], 'sales_tax' => $sign * $amounts['sales_tax'], 'further_tax' => $sign * $amounts['further_tax'],
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day of the month */
    public static function range(string $period): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new BusinessRuleViolation('Period must look like 2026-10.');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $period, 'Asia/Karachi');

        return [$start->startOfMonth(), $start->endOfMonth()];
    }
}
