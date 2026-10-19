<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\PaymentService;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Filament\Resources\Accounts\PaymentResource;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Payment;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;

/** A payment to a party (on account), by bank transfer or cash; released after approval (at once for the Owner / Super Admin). */
final class RequestPaymentTool extends Tool
{
    public function name(): string
    {
        return 'request_payment';
    }

    public function description(): string
    {
        return 'Pay a party (supplier, grower, transporter…) an amount in rupees on account, by ibft, rtgs, raast, cheque, pay_order or cash (default ibft from the first bank account). '
            .'It is released after approval; the Owner\'s and Super Admin\'s payment is released at once. To pay specific bills, use the Payments screen.';
    }

    public function properties(): array
    {
        return [
            'party' => ['type' => 'string'], 'amount' => ['type' => 'number', 'description' => 'Rupees.'],
            'mode' => ['type' => 'string', 'enum' => array_keys(Payment::MODES)], 'note' => ['type' => 'string'],
        ];
    }

    public function required(): array
    {
        return ['party', 'amount'];
    }

    public function permission(): string
    {
        return 'payment.create';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/^pay\s+(?<party>[\w .&]{2,50}?)\s+(?:rs\.?\s*)?(?<amount>[\d,]+(?:\.\d{1,2})?)(?:\s+by\s+(?<mode>ibft|rtgs|raast|cheque|pay_order|cash))?(?:\s*[:\-]\s*(?<note>.+))?$/i'];
    }

    public function examples(): array
    {
        return ['pay Haji Riaz 250000 by ibft'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        $party = $this->party((string) ($input['party'] ?? ''));

        return 'Pay '.Money::rupees((string) str_replace(',', '', (string) ($input['amount'] ?? '0')))->format().' to '.($party === null ? $input['party'] : $party->name)
            .' by '.(Payment::MODES[$input['mode'] ?? 'ibft'] ?? $input['mode']).(filled($input['note'] ?? null) ? ' — '.$input['note'] : '').'.';
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $party = $this->party((string) $input['party']);
        if ($party === null) {
            return ToolResult::fail('No party matches "'.$input['party'].'".');
        }
        $mode = (string) ($input['mode'] ?? 'ibft');
        $from = $mode === 'cash' ? app(ChartOfAccounts::class)->system('cash') : LedgerAccount::query()->where('kind', 'bank')->orderBy('code')->first();
        if ($from === null) {
            return ToolResult::fail('No mill bank account is set up.');
        }
        $payment = app(PaymentService::class)->request($party, $mode, $from, Money::rupees((string) str_replace(',', '', (string) $input['amount']))->paise,
            CarbonImmutable::now('Asia/Karachi')->startOfDay(), [], $input['note'] ?? 'Through the assistant');

        return new ToolResult("Payment {$payment->number}: ".($payment->status === Payment::APPROVED ? 'released.' : 'waiting for approval.'),
            [['label' => 'Open payment', 'url' => PaymentResource::getUrl('view', ['record' => $payment])]]);
    }

    private function party(string $words): ?Party
    {
        $like = '%'.mb_strtolower(trim($words)).'%';

        return trim($words) === '' ? null : Party::query()->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]))->orderBy('name')->first();
    }
}
