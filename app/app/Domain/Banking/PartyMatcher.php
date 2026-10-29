<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Models\Masters\Party;
use App\Models\Masters\PartyBankAccount;
use App\Support\BlindIndex;
use App\Support\Values\PakistanIds;

/**
 * Which party a bank transaction belongs to, only on verified facts:
 *
 *  1. the other side's full account number / IBAN is a registered bank account of exactly one party
 *     → matched;
 *  2. the party's code appears in the transaction reference (e.g. a buyer quoting "PTY-ALI-0001")
 *     → matched, if only one party's code does;
 *  3. only the last digits of an account, or the name, fit one or more parties → suggested: a person
 *     must confirm it;
 *  4. otherwise → unmatched.
 *
 * A name alone never matches. An uncertain transaction is never given a party by itself.
 *
 * @phpstan-type Result array{status: string, party_id: string|null, reason: string}
 */
final class PartyMatcher
{
    /** @return array{status: string, party_id: string|null, reason: string} */
    public function match(?string $counterpartyAccount, ?string $reference, ?string $counterpartyName): array
    {
        $digits = $counterpartyAccount === null ? '' : (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($counterpartyAccount));

        // 1. A full account number / IBAN.
        if ($digits !== '' && ! str_contains((string) $counterpartyAccount, '*') && strlen($digits) >= 10) {
            $index = (string) BlindIndex::of((string) PakistanIds::normalizeIban($digits), 'bank_account');
            $parties = PartyBankAccount::query()->where('account_number_index', $index)->distinct()->pluck('party_id');
            if ($parties->count() === 1) {
                return ['status' => 'matched', 'party_id' => (string) $parties->first(), 'reason' => 'Account '.substr($digits, -4).' is registered to this party.'];
            }
            if ($parties->count() > 1) {
                return ['status' => 'unmatched', 'party_id' => null, 'reason' => 'This account is registered to more than one party; choose one.'];
            }
        }

        // 2. A party code in the reference.
        if ($reference !== null && $reference !== '') {
            $hits = Party::query()->get(['id', 'code'])
                ->filter(fn (Party $p) => strlen($p->code) >= 4 && preg_match('/(^|[^A-Z0-9])'.preg_quote(strtoupper($p->code), '/').'([^A-Z0-9]|$)/', strtoupper($reference)) === 1);
            if ($hits->count() === 1) {
                return ['status' => 'matched', 'party_id' => (string) $hits->first()->id, 'reason' => 'The reference carries the party code '.$hits->first()->code.'.'];
            }
        }

        // 3. Hints only: the last four digits, or the name.
        $last4 = strlen($digits) >= 4 ? substr($digits, -4) : null;
        $byDigits = $last4 === null ? collect() : PartyBankAccount::query()->where('account_last4', $last4)->distinct()->pluck('party_id');
        if ($byDigits->count() === 1) {
            return ['status' => 'suggested', 'party_id' => (string) $byDigits->first(), 'reason' => "Only the last digits ({$last4}) fit this party: check before confirming."];
        }
        if ($counterpartyName !== null && mb_strlen(trim($counterpartyName)) >= 3) {
            $byName = Party::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($counterpartyName))])->limit(2)->pluck('id');
            if ($byName->count() === 1) {
                return ['status' => 'suggested', 'party_id' => (string) $byName->first(), 'reason' => 'Only the name fits this party: check before confirming.'];
            }
        }

        return ['status' => 'unmatched', 'party_id' => null, 'reason' => $byDigits->count() > 1 ? 'Several parties have an account ending '.$last4.'.' : 'No registered party fits.'];
    }
}
