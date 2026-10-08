<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Gate\GateService;
use App\Domain\Procurement\SaudaService;
use App\Domain\Sales\DispatchService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Masters\TaxRate;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\SalesBill;
use App\Support\Values\IndianIds;

/**
 * Accounts fixtures plus sales: the mill is in Haryana (06); a Delhi buyer (07, inter-state,
 * credit limit ₹5 lakh) and a Haryana buyer (no limit); rice at 5 % GST, 20,000 kg in the store;
 * a confirmed sales sauda at ₹4,000 / qtl, 30 days' credit, ±5 %.
 */
final class SalesFixtures
{
    public AccountsFixtures $acc;

    public InventoryFixtures $inv;

    public Party $delhiBuyer;

    public Party $localBuyer;

    public Sauda $sauda;

    public LoginAccount $dispatcher;

    public function __construct(public Entity $entity)
    {
        app(TenantContext::class)->runAsSystem(fn () => Entity::query()->whereKey($entity->id)->update(['state_code' => '06']));
        $this->acc = new AccountsFixtures($entity);
        $this->inv = $this->acc->inv;
        $this->dispatcher = $this->inv->supervisor; // supervisor: dispatch.manage, gatepass.issue, gate + weighbridge

        $this->as($this->acc->owner);
        $gst5 = TaxRate::query()->create(['name' => 'GST 5%', 'gst_rate_bp' => 500]);
        $this->inv->rice->forceFill(['tax_rate_id' => $gst5->id, 'hsn_code' => '10063020'])->save();
        $this->delhiBuyer = Party::query()->create([
            'code' => 'C-DEL', 'name' => 'Delhi Rice House', 'is_customer' => true, 'state_code' => '07',
            'gst_registration_type' => 'regular', 'gstin' => self::gstin('07', 'AAACD1234', 'C'), 'credit_limit_paise' => 5_00_000_00,
        ]);
        $this->localBuyer = Party::query()->create([
            'code' => 'C-KNL', 'name' => 'Karnal Traders', 'is_customer' => true, 'state_code' => '06',
            'gst_registration_type' => 'regular', 'gstin' => self::gstin('06', 'AAAFK1234', 'F'),
        ]);
        $this->inv->stock($this->inv->rice, $this->inv->store, '20000');
        $this->sauda = $this->saleSauda($this->delhiBuyer);
        Make::reset();
    }

    public function as(LoginAccount $account): void
    {
        $this->inv->as($account);
    }

    public static function gstin(string $state, string $panBody9, string $panLast): string
    {
        $first14 = $state.substr($panBody9.'000000000', 0, 9).$panLast.'1Z';

        return $first14.IndianIds::gstinCheckChar($first14);
    }

    public function saleSauda(Party $buyer, string $quantityKg = '50000', int $rate = 400000): Sauda
    {
        $this->as($this->acc->owner);
        $saudas = app(SaudaService::class);
        $sauda = $saudas->create([
            'direction' => 'sale', 'party_id' => $buyer->id, 'product_id' => $this->inv->rice->id, 'quantity_kg' => $quantityKg,
            'rate_paise_per_quintal' => $rate, 'payment_days' => 30,
            'delivery_from' => now()->toDateString(), 'delivery_to' => now()->addDays(30)->toDateString(),
        ]);
        $saudas->submit($sauda);
        $this->as($this->acc->owner2);

        return $saudas->approve($sauda);
    }

    public function order(?Sauda $sauda = null, string $kg = '10000', ?int $bags = null, ?string $bagKg = null): DispatchOrder
    {
        $this->as($this->dispatcher);

        return app(DispatchService::class)->create($sauda ?? $this->sauda, $this->inv->store, $kg, $bags, $bagKg);
    }

    /** Vehicle arrives for the order, tare 9,000 kg, loaded, gross = tare + $netKg, slip issued. */
    public function loadAndWeigh(DispatchOrder $order, string $netKg = '10050', ?int $bags = null): GateEntry
    {
        $this->as($this->dispatcher);
        $g = app(GateService::class);
        $e = $g->arrive(['registration_no' => 'HR05AB1234', 'dispatch_order_id' => $order->id]);
        $g->recordWeighment($e, 'tare', '9000', $this->inv->gate->scale, GateFixtures::readings('9000'), str_repeat('c', 64));
        $g->markLoaded($e, $bags);
        $gross = bcadd('9000', $netKg, 3);
        $g->recordWeighment($e, 'gross', $gross, $this->inv->gate->scale, GateFixtures::readings($gross), str_repeat('d', 64));
        $g->issueSlip($e);

        return $e->fresh();
    }

    public function billFor(GateEntry $entry): SalesBill
    {
        return SalesBill::query()->where('gate_entry_id', $entry->id)->sole();
    }
}
