<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Voucher;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Party;
use App\Support\Values\Money;

/**
 * The texts of the messages to parties (layout: MessageText), and when they go:
 *  - each money entry with a party (purchase bill, payment, receipt, sales invoice, note, reversal), with
 *    the party's balance after it;
 *  - a vehicle unloaded at the mill;
 *  - a vehicle's final weights (slip issued).
 */
final class PartyEventMessages
{
    public function __construct(private readonly PartyMessenger $messenger) {}

    /**
     * A voucher was posted: each party it touches hears what it was and where its account stands.
     *
     * @param  list<array{account: LedgerAccount, dr?: int, cr?: int}>  $lines
     */
    public function voucherPosted(Voucher $voucher, array $lines, callable $balanceOf): void
    {
        foreach ($lines as $line) {
            $ledger = $line['account'];
            if ($ledger->party_id === null) {
                continue;
            }
            $party = Party::query()->find($ledger->party_id);
            if ($party === null) {
                continue;
            }
            $dr = (int) ($line['dr'] ?? 0);
            $amount = Money::paise($dr > 0 ? $dr : (int) ($line['cr'] ?? 0))->format();
            $title = match (true) {
                $voucher->reverses_id !== null => 'Correction of an earlier entry',
                $voucher->type === 'purchase' => 'Purchase bill for your goods',
                $voucher->type === 'payment' => 'Payment made to you',
                $voucher->type === 'receipt' => 'Payment received from you',
                $voucher->type === 'sales' => 'Sales invoice to you',
                $voucher->type === 'debit_note' => 'Debit note',
                $voucher->type === 'credit_note' => 'Credit note',
                default => $dr > 0 ? 'Entry debited to your account' : 'Entry credited to your account',
            };
            $balance = (int) $balanceOf($ledger);
            $text = MessageText::make($this->messenger->millName($party->entity_id), $title, [
                ['Amount', $amount, true],
                ['Entry', $voucher->number.' · '.$voucher->voucher_date->format('d M Y')],
                ['Balance', self::standing($balance), true],
            ]);
            $this->messenger->send($party, 'money', $text, $voucher);
        }
    }

    /** "We owe you Rs …" / "You owe us Rs …" / "Your account is settled" (a party ledger: debit = owes us). */
    public static function standing(int $balance): string
    {
        return match (true) {
            $balance < 0 => 'We owe you '.Money::paise(-$balance)->format(),
            $balance > 0 => 'You owe us '.Money::paise($balance)->format(),
            default => 'Your account is settled',
        };
    }

    /** An inbound vehicle was unloaded at the mill. */
    public function unloaded(GateEntry $entry): void
    {
        $party = $entry->party_id !== null ? Party::query()->find($entry->party_id) : null;
        if ($party === null) {
            return;
        }
        $text = MessageText::make($this->messenger->millName($entry->entity_id), 'Your vehicle was unloaded at the mill', array_values(array_filter([
            ['Vehicle', $entry->vehicleLabel(), true],
            $entry->token_no !== null || $entry->token_serial !== null ? ['Token', $entry->tokenLabel(), true] : null,
            ['Item', $entry->itemLabel()],
            $entry->gross_kg !== null ? ['Loaded weight', self::kg($entry->gross_kg), true] : null,
            ['Time', now('Asia/Karachi')->format('d M Y, h:i A')],
        ])));
        $this->messenger->send($party, 'unloaded', $text, $entry);
    }

    /** A vehicle's weights are final (the slip was issued). */
    public function weighed(GateEntry $entry): void
    {
        $party = $entry->party_id !== null ? Party::query()->find($entry->party_id) : null;
        if ($party === null || $entry->net_kg === null) {
            return;
        }
        $deducted = $entry->payable_kg !== null && bccomp((string) $entry->payable_kg, (string) $entry->net_kg, 3) !== 0;
        $text = MessageText::make($this->messenger->millName($entry->entity_id), 'Final weights of your vehicle', array_values(array_filter([
            ['Vehicle', $entry->vehicleLabel(), true],
            ['Slip', (string) $entry->number],
            ['Item', $entry->itemLabel()],
            ['Loaded', self::kg($entry->gross_kg)],
            ['Empty', self::kg($entry->tare_kg)],
            ['Net weight', self::kg($entry->net_kg), true],
            $deducted ? ['Payable weight', self::kg($entry->payable_kg), true] : null,
        ])));
        $this->messenger->send($party, 'weighed', $text, $entry);
    }

    /** "15,250 kg (381.25 maund)". */
    private static function kg(?string $kg): string
    {
        return $kg === null ? '—' : number_format((float) $kg, 0).' kg ('.number_format((float) $kg / 40, 2).' maund)';
    }
}
