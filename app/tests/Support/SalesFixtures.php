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

/**
 * Accounts fixtures plus sales: the mill is registered for sales tax (STRN); a Karachi buyer
 * (registered, credit limit Rs 500,000) and a Multan buyer (registered, no limit); rice at a 5 %
 * sales-tax rate, 20,000 kg in the store; a confirmed sales sauda at Rs 1,600 / maund (Rs 4,000 /
 * quintal), 30 days' credit, ±5 %.
 */
final class SalesFixtures
{
    public AccountsFixtures $acc;

    public InventoryFixtures $inv;

    public Party $karachiBuyer;

    public Party $multanBuyer;

    public Sauda $sauda;

    public LoginAccount $dispatcher;

    public function __construct(public Entity $entity)
    {
        app(TenantContext::class)->runAsSystem(fn () => Entity::query()->findOrFail($entity->id)->forceFill(['ntn' => '12345678', 'strn' => self::strn(1)])->save());
        $this->acc = new AccountsFixtures($entity);
        $this->inv = $this->acc->inv;
        $this->dispatcher = $this->inv->supervisor; // supervisor: dispatch.manage, gatepass.issue, gate + weighbridge

        $this->as($this->acc->owner);
        $tax5 = TaxRate::query()->create(['name' => 'Reduced 5%', 'sales_tax_rate_bp' => 500]);
        $this->inv->rice->forceFill(['tax_rate_id' => $tax5->id, 'pct_code' => '1006.3000'])->save();
        $this->karachiBuyer = Party::query()->create([
            'code' => 'C-KHI', 'name' => 'Karachi Rice House', 'is_customer' => true, 'city' => 'Karachi', 'province' => 'Sindh',
            'ntn' => '23456789', 'strn' => self::strn(2), 'atl_status' => 'active', 'credit_limit_paise' => 500_000_00,
        ]);
        $this->multanBuyer = Party::query()->create([
            'code' => 'C-MUX', 'name' => 'Multan Traders', 'is_customer' => true, 'city' => 'Multan', 'province' => 'Punjab',
            'ntn' => '34567890', 'strn' => self::strn(3), 'atl_status' => 'active',
        ]);
        $this->inv->stock($this->inv->rice, $this->inv->store, '20000');
        $this->sauda = $this->saleSauda($this->karachiBuyer);
        Make::reset();
    }

    public function as(LoginAccount $account): void
    {
        $this->inv->as($account);
    }

    /** A 13-digit STRN that differs per $n. */
    public static function strn(int $n): string
    {
        return '32778765'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    public function saleSauda(Party $buyer, string $quantityKg = '50000', int $rate = 160000): Sauda
    {
        $this->as($this->acc->owner);
        $saudas = app(SaudaService::class);
        $sauda = $saudas->create([
            'direction' => 'sale', 'party_id' => $buyer->id, 'product_id' => $this->inv->rice->id, 'quantity_kg' => $quantityKg,
            'rate_paise_per_maund' => $rate, 'payment_days' => 30,
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
        $e = $g->arrive(['registration_no' => 'MNA211234', 'dispatch_order_id' => $order->id]);
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
