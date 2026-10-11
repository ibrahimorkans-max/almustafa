<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Models\Accounts\Voucher;
use App\Models\Hr\Employee;
use App\Models\Hr\Reimbursement;
use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/** Reimbursement voucher: the claim's lines by category, the receipts as thumbnails, who checked and approved, and payment. */
final class ReimbursementVoucherDocument extends BaseDocument
{
    public function key(): string
    {
        return 'reimbursement_voucher';
    }

    public function label(): string
    {
        return 'Reimbursement voucher';
    }

    public function sourceClass(): string
    {
        return Reimbursement::class;
    }

    public function isFinal(Model $source): bool
    {
        /** @var Reimbursement $source */
        return in_array($source->status, [Reimbursement::APPROVED, Reimbursement::PAID], true);
    }

    public function number(Model $source): string
    {
        /** @var Reimbursement $source */
        return $source->number;
    }

    public function series(): string
    {
        return 'RMB';
    }

    public function employeeId(Model $source): string
    {
        /** @var Reimbursement $source */
        return $source->employee_id;
    }

    public function build(Model $source, array $options): array
    {
        /** @var Reimbursement $source */
        $e = Employee::query()->with(['designation', 'department', 'person'])->findOrFail($source->employee_id);
        $lines = $source->lines()->with(['category', 'receipt'])->orderBy('spent_on')->get();
        $name = fn (?string $id) => $id !== null ? LoginAccount::query()->whereKey($id)->value('username') : null;
        $images = [];
        foreach ($lines as $i => $l) {
            if ($l->receipt !== null && in_array($l->receipt->mime, ['image/jpeg', 'image/png'], true)) {
                $images[] = ['Receipt '.($i + 1).' · '.$l->spent_on->format('d M'), $l->receipt->id];
            }
        }

        return [
            'title' => 'reimbursement_voucher',
            'date' => ($source->approved_at ?? $source->created_at)?->timezone('Asia/Karachi')->toDateString(),
            'fields' => [
                ['date', ['d' => $source->claim_date->toDateString()]],
                ...array_slice($this->employeeFields($e), 0, 3),
                ['description', $source->purpose],
                ['approved_by', $name($source->approved_by)],
            ],
            'tables' => [[
                'k' => 'receipts', 'cols' => ['date', 'category', 'description', 'receipts', 'amount'], 'detail' => [],
                'rows' => $lines->values()->map(fn ($l, $i) => [['d' => $l->spent_on->toDateString()], $l->category->name, $l->description,
                    $l->receipt_file_id !== null ? (string) ($i + 1) : '—', self::money($l->amount_paise)])->all(),
                'total' => [null, null, ['k' => 'total'], null, self::money($source->total_paise)],
            ]],
            'summary' => [['total', $source->total_paise, true]],
            'words' => $source->total_paise,
            'images' => $images,
            'status' => array_values(array_filter([
                $source->voucher_id !== null ? ['voucher', (string) Voucher::query()->whereKey($source->voucher_id)->value('number')] : null,
                ['status', ['v' => $source->status === Reimbursement::PAID ? 'paid' : 'unpaid']],
                $source->payment_voucher_id !== null ? ['reference', (string) Voucher::query()->whereKey($source->payment_voucher_id)->value('number')] : null,
            ])),
            'signatures' => ['prepared_by', 'approved_by', 'received_by'],
        ];
    }
}
