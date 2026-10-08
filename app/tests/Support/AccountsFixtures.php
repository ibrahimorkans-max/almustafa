<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Gate\GateService;
use App\Domain\Procurement\SaudaService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\PurchaseBill;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\PartyBankAccount;
use App\Models\Procurement\Sauda;

/**
 * Inventory fixtures plus accounts: an accountant (makes payment requests and posts bills),
 * two owners (approve), a mill bank ledger, an approved bank account for the seller and a sauda
 * at ₹2,500 / qtl with ₹10 / qtl brokerage and 15 days' credit.
 */
final class AccountsFixtures
{
    public InventoryFixtures $inv;

    public LoginAccount $accountant;

    public LoginAccount $owner;

    public LoginAccount $owner2;

    public LedgerAccount $bank;

    public Sauda $sauda;

    public PartyBankAccount $sellerBank;

    public function __construct(public Entity $entity)
    {
        $this->inv = new InventoryFixtures($entity);
        $this->owner = $this->inv->gate->owner;
        $this->owner2 = $this->inv->gate->owner2;
        $this->accountant = Make::account($entity, 'ACCOUNTANT');

        $this->as($this->owner);
        $this->bank = LedgerAccount::query()->create([
            'entity_id' => $entity->id, 'group_id' => app(ChartOfAccounts::class)->group('BANK_ACCOUNTS')->id,
            'code' => 'BANK-SBI', 'name' => 'SBI current account', 'kind' => 'bank',
            'bank_account_number' => '30012345678', 'bank_ifsc' => 'SBIN0001234', 'bank_name' => 'State Bank of India',
        ]);
        // Normally created through the PartyBankAccountChange approval; set up directly here.
        $this->sellerBank = PartyBankAccount::query()->create([
            'entity_id' => $entity->id, 'party_id' => $this->inv->gate->seller->id, 'account_holder' => 'RAM SINGH',
            'account_number' => '1234567890123', 'ifsc' => 'PUNB0123400', 'bank_name' => 'Punjab National Bank', 'is_primary' => true, 'status' => 'active',
        ]);

        $saudas = app(SaudaService::class);
        $sauda = $saudas->create([
            'party_id' => $this->inv->gate->seller->id, 'broker_party_id' => $this->inv->gate->broker->id, 'product_id' => $this->inv->paddy->id,
            'quantity_kg' => '100000', 'rate_paise_per_quintal' => 250000, 'brokerage_paise_per_quintal' => 1000, 'payment_days' => 15,
            'delivery_from' => now()->toDateString(), 'delivery_to' => now()->addDays(30)->toDateString(),
        ]);
        $saudas->submit($sauda);
        $this->as($this->owner2);
        $this->sauda = $saudas->approve($sauda);
        Make::reset();
    }

    public function as(LoginAccount $account): void
    {
        $this->inv->as($account);
    }

    /**
     * Accepted lot: net 15,250 kg; moisture 18.5 % (−228.75 kg), 400 bags (−240 kg) → payable 14,781.25 kg;
     * foreign matter 3.5 % → ₹10 / qtl rate cut. Unloaded into the silo; slip issued.
     */
    public function receiveLot(): GateEntry
    {
        $this->as($this->inv->supervisor);
        $g = app(GateService::class);
        $e = $g->arrive(['registration_no' => 'HR05AB1234', 'sauda_id' => $this->sauda->id, 'bag_count' => 400]);
        $g->recordWeighment($e, 'gross', '25480', $this->inv->gate->scale, GateFixtures::readings('25480'), str_repeat('a', 64));
        $g->recordLabTest($e, ['moisture_bp' => 1850, 'foreign_matter_bp' => 350]);
        $g->decideLab($e, true);
        $g->markUnloaded($e, godown: $this->inv->silo);
        $g->recordWeighment($e, 'tare', '10230', $this->inv->gate->scale, GateFixtures::readings('10230'), str_repeat('a', 64));
        $g->issueSlip($e);
        $fresh = $e->fresh();
        Make::reset();

        return $fresh;
    }

    public function billFor(GateEntry $entry): PurchaseBill
    {
        return app(TenantContext::class)->runInEntity($this->entity->id, fn () => PurchaseBill::query()->where('gate_entry_id', $entry->id)->sole());
    }
}
