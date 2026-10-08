<?php

declare(strict_types=1);

namespace App\Domain\Gst;

use App\Domain\Accounts\PeriodLocks;
use App\Domain\Claims\ClaimService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\Voucher;
use App\Models\Claims\Claim;
use App\Models\Entity;
use App\Models\Sales\SalesBill;
use Carbon\CarbonImmutable;

/**
 * GST return data for one month, computed only from posted invoices and posted credit notes.
 * Amounts in paise; the portal JSON converts to rupees with two decimals.
 */
final class GstReturns
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PeriodLocks $periods,
    ) {}

    /**
     * @return array{period: string, locked: bool, b2b: list<array<string, mixed>>, b2cs: list<array<string, mixed>>, cdnr: list<array<string, mixed>>, hsn: list<array<string, mixed>>, nil: array{taxable: int}}
     */
    public function gstr1(string $period): array
    {
        [$from, $to] = self::range($period);
        $bills = SalesBill::query()->where('status', SalesBill::POSTED)
            ->whereBetween('bill_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('bill_date')->orderBy('number')->get();

        $b2b = [];
        $b2cs = [];
        $hsn = [];
        $nil = 0;
        foreach ($bills as $bill) {
            $line = self::taxLine($bill->taxable_paise, $bill->igst_paise, $bill->cgst_paise, $bill->sgst_paise, $bill->gst_rate_bp);
            if ($bill->gst_rate_bp === 0) {
                $nil += $bill->taxable_paise;
            }
            if ($bill->buyer_gstin !== null) {
                $b2b[] = [
                    'ctin' => $bill->buyer_gstin, 'number' => $bill->number, 'date' => $bill->bill_date->toDateString(),
                    'value' => $bill->total_paise, 'pos' => $bill->place_of_supply, 'reverse_charge' => 'N',
                ] + $line;
            } else {
                $key = ($bill->isInterState() ? 'INTER' : 'INTRA').'|'.$bill->place_of_supply.'|'.$bill->gst_rate_bp;
                $b2cs[$key] ??= ['supply' => $bill->isInterState() ? 'INTER' : 'INTRA', 'pos' => $bill->place_of_supply, 'rate_bp' => $bill->gst_rate_bp,
                    'taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0];
                foreach (['taxable', 'igst', 'cgst', 'sgst'] as $k) {
                    $b2cs[$key][$k] += $line[$k];
                }
            }
            $hk = ($bill->hsn_code ?? 'NA').'|'.$bill->gst_rate_bp;
            $hsn[$hk] ??= ['hsn' => $bill->hsn_code ?? 'NA', 'description' => (string) ($bill->snapshot['product']['name'] ?? ''), 'uqc' => 'KGS',
                'quantity_kg' => '0', 'rate_bp' => $bill->gst_rate_bp, 'taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0];
            $hsn[$hk]['quantity_kg'] = bcadd($hsn[$hk]['quantity_kg'], $bill->quantity_kg, 3);
            foreach (['taxable', 'igst', 'cgst', 'sgst'] as $k) {
                $hsn[$hk][$k] += $line[$k];
            }
        }

        return [
            'period' => $period,
            'locked' => $this->periods->isLocked($from),
            'b2b' => $b2b,
            'b2cs' => array_values($b2cs),
            'cdnr' => $this->creditNotes($from, $to),
            'hsn' => array_values($hsn),
            'nil' => ['taxable' => $nil],
        ];
    }

    /**
     * GSTR-3B summary of outward supplies (input tax credit is entered by hand in this phase).
     *
     * @return array{outward_taxable: array{taxable: int, igst: int, cgst: int, sgst: int}, nil_rated: int, inter_state_unregistered: list<array{pos: string|null, taxable: int, igst: int}>}
     */
    public function gstr3b(string $period): array
    {
        $r1 = $this->gstr1($period);
        $taxable = ['taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0];
        foreach ([...$r1['b2b'], ...$r1['b2cs']] as $row) {
            if ($row['rate_bp'] === 0) {
                continue;
            }
            foreach (array_keys($taxable) as $k) {
                $taxable[$k] += $row[$k];
            }
        }
        foreach ($r1['cdnr'] as $note) {
            if ($note['rate_bp'] === 0) {
                continue;
            }
            foreach (array_keys($taxable) as $k) {
                $taxable[$k] -= $note[$k];
            }
        }
        $inter = [];
        foreach ($r1['b2cs'] as $row) {
            if ($row['supply'] === 'INTER' && $row['rate_bp'] > 0) {
                $inter[(string) $row['pos']] ??= ['pos' => $row['pos'], 'taxable' => 0, 'igst' => 0];
                $inter[(string) $row['pos']]['taxable'] += $row['taxable'];
                $inter[(string) $row['pos']]['igst'] += $row['igst'];
            }
        }

        return ['outward_taxable' => $taxable, 'nil_rated' => $r1['nil']['taxable'], 'inter_state_unregistered' => array_values($inter)];
    }

    /**
     * GSTR-1 in the GST portal's JSON layout (rupees, 2 decimals; dates dd-mm-yyyy).
     *
     * @return array<string, mixed>
     */
    public function gstr1Json(string $period): array
    {
        $r = $this->gstr1($period);
        $entityId = $this->tenant->requireEntityId();
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($entityId));
        $rs = fn (int $p) => round($p / 100, 2);
        $rate = fn (int $bp) => round($bp / 100, 2);
        $item = fn (array $l) => ['num' => 1, 'itm_det' => [
            'txval' => $rs($l['taxable']), 'rt' => $rate($l['rate_bp']), 'iamt' => $rs($l['igst']), 'camt' => $rs($l['cgst']), 'samt' => $rs($l['sgst']), 'csamt' => 0,
        ]];
        $date = fn (string $d) => CarbonImmutable::parse($d)->format('d-m-Y');

        $b2b = [];
        foreach ($r['b2b'] as $inv) {
            $b2b[$inv['ctin']]['ctin'] = $inv['ctin'];
            $b2b[$inv['ctin']]['inv'][] = [
                'inum' => $inv['number'], 'idt' => $date($inv['date']), 'val' => $rs($inv['value']), 'pos' => $inv['pos'],
                'rchrg' => 'N', 'inv_typ' => 'R', 'itms' => [$item($inv)],
            ];
        }
        $cdnr = [];
        foreach ($r['cdnr'] as $note) {
            if ($note['ctin'] === null) {
                continue;
            }
            $cdnr[$note['ctin']]['ctin'] = $note['ctin'];
            $cdnr[$note['ctin']]['nt'][] = [
                'ntty' => 'C', 'nt_num' => $note['number'], 'nt_dt' => $date($note['date']), 'val' => $rs($note['value']),
                'pos' => $note['pos'], 'rchrg' => 'N', 'inv_typ' => 'R', 'itms' => [$item($note)],
            ];
        }

        return [
            'gstin' => $entity?->gstin,
            'fp' => CarbonImmutable::createFromFormat('Y-m', $period)->format('mY'),
            'b2b' => array_values($b2b),
            'b2cs' => array_map(fn (array $row) => [
                'sply_ty' => $row['supply'], 'pos' => $row['pos'], 'typ' => 'OE', 'rt' => $rate($row['rate_bp']),
                'txval' => $rs($row['taxable']), 'iamt' => $rs($row['igst']), 'camt' => $rs($row['cgst']), 'samt' => $rs($row['sgst']), 'csamt' => 0,
            ], $r['b2cs']),
            'cdnr' => array_values($cdnr),
            'hsn' => ['data' => array_map(fn (array $h, int $i) => [
                'num' => $i + 1, 'hsn_sc' => $h['hsn'], 'desc' => mb_substr($h['description'], 0, 30), 'uqc' => $h['uqc'],
                'qty' => (float) $h['quantity_kg'], 'rt' => $rate($h['rate_bp']), 'txval' => $rs($h['taxable']),
                'iamt' => $rs($h['igst']), 'camt' => $rs($h['cgst']), 'samt' => $rs($h['sgst']), 'csamt' => 0,
            ], $r['hsn'], array_keys($r['hsn']))],
        ];
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
            $bill = SalesBill::query()->find($claim->document_id);
            if ($bill === null) {
                continue;
            }
            $split = ClaimService::split((int) $claim->settled_paise, $bill);
            $notes[] = [
                'ctin' => $bill->buyer_gstin, 'number' => $voucher->number, 'date' => $voucher->voucher_date->toDateString(),
                'value' => (int) $claim->settled_paise, 'pos' => $bill->place_of_supply, 'original_invoice' => $bill->number,
            ] + self::taxLine($split['taxable'], $split['igst'], $split['cgst'], $split['sgst'], $bill->gst_rate_bp);
        }

        return $notes;
    }

    /** @return array{taxable: int, igst: int, cgst: int, sgst: int, rate_bp: int} */
    private static function taxLine(int $taxable, int $igst, int $cgst, int $sgst, int $rateBp): array
    {
        return ['taxable' => $taxable, 'igst' => $igst, 'cgst' => $cgst, 'sgst' => $sgst, 'rate_bp' => $rateBp];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day of the month */
    public static function range(string $period): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new BusinessRuleViolation('Period must look like 2026-10.');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $period, 'Asia/Kolkata');

        return [$start->startOfMonth(), $start->endOfMonth()];
    }
}
