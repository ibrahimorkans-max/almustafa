<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Models\Accounts\Voucher;
use App\Models\Hr\ContractorBill;
use App\Models\Masters\Party;
use App\Support\Values\PakistanIds;
use Illuminate\Database\Eloquent\Model;

/** Labour contractor bill: workers' man-days or piece work × rate, sales tax on services, tax withheld, net payable. */
final class ContractorBillDocument extends BaseDocument
{
    public function key(): string
    {
        return 'contractor_bill';
    }

    public function label(): string
    {
        return 'Labour contractor bill';
    }

    public function sourceClass(): string
    {
        return ContractorBill::class;
    }

    public function isFinal(Model $source): bool
    {
        /** @var ContractorBill $source */
        return $source->status === 'approved';
    }

    public function number(Model $source): string
    {
        /** @var ContractorBill $source */
        return $source->number;
    }

    public function series(): string
    {
        return 'CB';
    }

    public function employeeId(Model $source): ?string
    {
        return null;
    }

    public function build(Model $source, array $options): array
    {
        /** @var ContractorBill $source */
        $party = Party::query()->findOrFail($source->party_id);
        $lines = $source->lines()->orderBy('description')->get();
        $summary = [['gross', $source->gross_paise]];
        if ($source->sales_tax_paise > 0) {
            $summary[] = ['sales_tax', $source->sales_tax_paise];
        }
        if ($source->wht_paise > 0) {
            $summary[] = ['wht', -$source->wht_paise];
        }
        $summary[] = ['payable', $source->payable_paise, true];

        return [
            'title' => 'contractor_bill',
            'date' => $source->period_to->toDateString(),
            'fields' => [
                ['contractor', $party->name],
                ['period', $source->period_from->format('d M Y').' – '.$source->period_to->format('d M Y')],
                ['kind', ContractorBill::BASES[$source->basis] ?? $source->basis],
                ['ntn', PakistanIds::formatNtn($party->ntn)],
            ],
            'tables' => [[
                'k' => $source->basis === 'man_days' ? 'workers' : 'units', 'cols' => ['description', 'quantity', 'unit', 'rate', 'amount'],
                'rows' => $lines->map(fn ($l) => [$l->description, ['n' => rtrim(rtrim((string) $l->quantity, '0'), '.')], $l->unit, self::money($l->rate_paise), self::money($l->amount_paise)])->all(),
                'total' => [['k' => 'total'], null, null, null, self::money($source->gross_paise)],
            ]],
            'summary' => $summary,
            'words' => $source->payable_paise,
            'status' => array_values(array_filter([
                $source->voucher_id !== null ? ['voucher', (string) Voucher::query()->whereKey($source->voucher_id)->value('number')] : null,
            ])),
            'signatures' => ['prepared_by', 'approved_by', 'contractor'],
        ];
    }
}
