<?php

declare(strict_types=1);

namespace App\Domain\Claims;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\PurchaseBill;
use App\Models\Claims\Claim;
use App\Models\Claims\ClaimEvent;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use App\Models\StoredFile;
use App\Support\Values\Money;
use Illuminate\Support\Facades\DB;

/**
 * Claims: raised and worked by `claim.manage`; the settlement amount is approved (or the claim
 * rejected) by a different person with `claim.approve`; accounts then posts the credit note (sales)
 * or debit note (purchase). Every step is an append-only claim event.
 */
final class ClaimService
{
    public const DOCUMENT_TYPES = [Claim::SALES, Claim::PURCHASE];

    public function __construct(
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
    ) {}

    /** @param  list<string>  $evidence  stored-file sha256 */
    public function raise(SalesBill|PurchaseBill $document, string $type, string $description, int $claimedPaise, array $evidence = [], ?Party $transporter = null): Claim
    {
        $this->require('claim.manage');
        if (! isset(Claim::TYPES[$type])) {
            throw new BusinessRuleViolation('Unknown claim type.');
        }
        if ($document->status !== 'posted') {
            throw new BusinessRuleViolation('Claims are raised against posted invoices and bills.');
        }
        if (mb_strlen(trim($description)) < 10) {
            throw new BusinessRuleViolation('Describe the claim (at least 10 characters).');
        }
        if ($claimedPaise <= 0) {
            throw new BusinessRuleViolation('The amount claimed must be more than zero.');
        }
        $room = $this->room($document);
        if ($claimedPaise > $room) {
            throw new BusinessRuleViolation(sprintf('At most %s can still be claimed on this document.', Money::paise($room)->format()));
        }
        $this->assertFilesExist($evidence);

        return DB::transaction(function () use ($document, $type, $description, $claimedPaise, $evidence, $transporter): Claim {
            $claim = Claim::query()->create([
                'number' => $this->numbers->next(DocumentNumbers::CLAIM),
                'document_type' => $document instanceof SalesBill ? Claim::SALES : Claim::PURCHASE,
                'document_id' => $document->id,
                'party_id' => $document->party_id,
                'transporter_party_id' => $transporter?->id,
                'type' => $type,
                'description' => $description,
                'claimed_paise' => $claimedPaise,
                'evidence' => array_values(array_unique($evidence)),
            ]);
            $this->event($claim, 'raised', ['claimed_paise' => $claimedPaise, 'type' => $type, 'evidence' => $claim->evidence]);

            return $claim;
        });
    }

    public function note(Claim $claim, string $text): Claim
    {
        $this->require('claim.manage');
        $this->assertStatus($claim, Claim::OPEN, Claim::APPROVED);
        if (mb_strlen(trim($text)) < 3) {
            throw new BusinessRuleViolation('Write a note.');
        }
        $this->event($claim, 'note', ['text' => $text]);

        return $claim;
    }

    /** @param  list<string>  $sha256s */
    public function addEvidence(Claim $claim, array $sha256s): Claim
    {
        $this->require('claim.manage');
        $this->assertStatus($claim, Claim::OPEN);
        $this->assertFilesExist($sha256s);

        return DB::transaction(function () use ($claim, $sha256s): Claim {
            $claim = $this->lock($claim);
            $claim->evidence = array_values(array_unique([...($claim->evidence ?? []), ...$sha256s]));
            $claim->save();
            $this->event($claim, 'evidence', ['added' => $sha256s]);

            return $claim;
        });
    }

    /** The approver fixes how much is settled (at most the claim, and at most what can still be claimed). */
    public function approve(Claim $claim, int $settledPaise, ?string $note = null): Claim
    {
        $approver = $this->require('claim.approve');

        return DB::transaction(function () use ($claim, $settledPaise, $note, $approver): Claim {
            $claim = $this->lock($claim);
            $this->assertStatus($claim, Claim::OPEN);
            $this->assertNotRaiser($claim, $approver);
            if ($settledPaise <= 0 || $settledPaise > $claim->claimed_paise) {
                throw new BusinessRuleViolation('The settlement must be more than zero and not more than the amount claimed.');
            }
            $room = $this->room($this->document($claim), $claim->id);
            if ($settledPaise > $room) {
                throw new BusinessRuleViolation(sprintf('Other settled claims leave only %s on this document.', Money::paise($room)->format()));
            }
            $claim->forceFill(['status' => Claim::APPROVED, 'settled_paise' => $settledPaise])->save();
            $this->event($claim, 'approved', ['settled_paise' => $settledPaise, 'claimed_paise' => $claim->claimed_paise, 'note' => $note]);

            return $claim;
        });
    }

    public function reject(Claim $claim, string $reason): Claim
    {
        $approver = $this->require('claim.approve');

        return DB::transaction(function () use ($claim, $reason, $approver): Claim {
            $claim = $this->lock($claim);
            $this->assertStatus($claim, Claim::OPEN);
            $this->assertNotRaiser($claim, $approver);
            $claim->forceFill(['status' => Claim::REJECTED])->save();
            $this->event($claim, 'rejected', ['reason' => $reason]);

            return $claim;
        });
    }

    public function withdraw(Claim $claim, string $reason): Claim
    {
        $this->require('claim.manage');

        return DB::transaction(function () use ($claim, $reason): Claim {
            $claim = $this->lock($claim);
            $this->assertStatus($claim, Claim::OPEN, Claim::APPROVED);
            $claim->forceFill(['status' => Claim::WITHDRAWN])->save();
            $this->event($claim, 'withdrawn', ['reason' => $reason]);

            return $claim;
        });
    }

    /**
     * Accounts posts the note. Sales: credit note that reverses GST in the invoice's own proportion.
     * Purchase: debit note to the supplier.
     */
    public function settle(Claim $claim): Claim
    {
        $this->require('ledger.post');

        return DB::transaction(function () use ($claim): Claim {
            $claim = $this->lock($claim);
            $this->assertStatus($claim, Claim::APPROVED);
            $document = $this->document($claim);
            $party = Party::query()->findOrFail($claim->party_id);
            $amount = (int) $claim->settled_paise;
            $memo = "Claim {$claim->number} against {$document->number}";

            if ($document instanceof SalesBill) {
                $split = self::split($amount, $document);
                $voucher = $this->books->post('credit_note', now(), [
                    ['account' => $this->chart->system('sales_claims'), 'dr' => $split['taxable'], 'memo' => $memo],
                    ['account' => $this->chart->system('output_cgst'), 'dr' => $split['cgst']],
                    ['account' => $this->chart->system('output_sgst'), 'dr' => $split['sgst']],
                    ['account' => $this->chart->system('output_igst'), 'dr' => $split['igst']],
                    ['account' => $this->chart->partyLedger($party), 'cr' => $amount, 'bill_id' => $document->id, 'memo' => $memo],
                ], "Credit note: {$memo} ({$claim->description})", ['source_type' => 'claim', 'source_id' => $claim->id]);
            } else {
                $voucher = $this->books->post('debit_note', now(), [
                    ['account' => $this->chart->partyLedger($party), 'dr' => $amount, 'bill_id' => $document->id, 'memo' => $memo],
                    ['account' => $this->chart->system('purchase_claims'), 'cr' => $amount, 'memo' => $memo],
                ], "Debit note: {$memo} ({$claim->description})", ['source_type' => 'claim', 'source_id' => $claim->id]);
            }

            $claim->forceFill(['status' => Claim::SETTLED, 'voucher_id' => $voucher->id])->save();
            $this->event($claim, 'settled', ['voucher' => $voucher->number, 'settled_paise' => $amount]);

            return $claim;
        });
    }

    /**
     * Splits a settlement in the invoice's proportion of taxable value and each tax (round-off excluded);
     * the taxable part absorbs the rounding so the parts always add up to the settlement.
     *
     * @return array{taxable: int, cgst: int, sgst: int, igst: int}
     */
    public static function split(int $amount, SalesBill $bill): array
    {
        $base = $bill->taxable_paise + $bill->cgst_paise + $bill->sgst_paise + $bill->igst_paise;
        $part = fn (int $tax) => $base === 0 ? 0 : intdiv($amount * $tax * 2 + $base, $base * 2);
        $cgst = $part($bill->cgst_paise);
        $sgst = $part($bill->sgst_paise);
        $igst = $part($bill->igst_paise);

        return ['taxable' => $amount - $cgst - $sgst - $igst, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst];
    }

    /** Total settled by claims on a document (approved amounts that were posted as notes). */
    public static function settledOn(string $documentId, ?string $exceptClaimId = null): int
    {
        return (int) Claim::query()->where('document_id', $documentId)->whereIn('status', [Claim::APPROVED, Claim::SETTLED])
            ->when($exceptClaimId, fn ($q) => $q->whereKeyNot($exceptClaimId))->sum('settled_paise');
    }

    /** Amount already posted as credit / debit notes against a document (reduces what is owed). */
    public static function notedOn(string $documentId): int
    {
        return (int) Claim::query()->where('document_id', $documentId)->where('status', Claim::SETTLED)->sum('settled_paise');
    }

    private function room(SalesBill|PurchaseBill $document, ?string $exceptClaimId = null): int
    {
        $total = $document instanceof SalesBill ? $document->total_paise : $document->net_paise;

        return $total - self::settledOn($document->id, $exceptClaimId);
    }

    private function document(Claim $claim): SalesBill|PurchaseBill
    {
        return $claim->document() ?? throw new BusinessRuleViolation('The document of this claim no longer exists.');
    }

    /** @param  list<string>  $sha256s */
    private function assertFilesExist(array $sha256s): void
    {
        foreach ($sha256s as $sha) {
            if (! preg_match('/^[0-9a-f]{64}$/', $sha) || ! StoredFile::query()->where('sha256', $sha)->exists()) {
                throw new BusinessRuleViolation('Evidence must be uploaded files.');
            }
        }
    }

    private function assertStatus(Claim $claim, string ...$allowed): void
    {
        if (! in_array($claim->status, $allowed, true)) {
            throw new BusinessRuleViolation("Claim {$claim->number} is {$claim->status}.");
        }
    }

    private function assertNotRaiser(Claim $claim, LoginAccount $decider): void
    {
        $raiser = $claim->created_by ? LoginAccount::query()->whereKey($claim->created_by)->value('person_id') : null;
        if ($raiser !== null && $raiser === $decider->person_id) {
            throw new BusinessRuleViolation('The person who raised the claim cannot decide it.');
        }
    }

    private function lock(Claim $claim): Claim
    {
        /** @var Claim */
        return Claim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @param  array<string, mixed>  $data */
    private function event(Claim $claim, string $event, array $data): void
    {
        ClaimEvent::query()->create([
            'entity_id' => $claim->entity_id, 'claim_id' => $claim->id, 'event' => $event, 'data' => $data,
            'actor_login_account_id' => $this->actor->accountId(), 'actor_person_id' => $this->actor->personId(), 'created_at' => now(),
        ]);
    }

    private function require(string $permission): LoginAccount
    {
        $account = $this->actor->account();
        if ($account === null || ! $account->hasPermission($permission)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }

        return $account;
    }
}
