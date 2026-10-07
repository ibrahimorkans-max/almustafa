<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Procurement\SaudaService;
use App\Models\Entity;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\Scale;
use App\Models\Gate\ScaleCertificate;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;

/** Typical gate setup for one entity: paddy product, seller, certified scale, deduction rules. */
final class GateFixtures
{
    public Product $paddy;

    public Party $seller;

    public Party $broker;

    public Scale $scale;

    public LoginAccount $owner;

    public LoginAccount $owner2;

    public function __construct(public Entity $entity)
    {
        $this->owner = Make::account($entity, 'OWNER');
        $this->owner2 = Make::account($entity, 'OWNER');
        $this->as($this->owner);

        $this->paddy = Product::query()->create(['code' => 'PDY-1121', 'name' => 'Paddy 1121', 'category' => 'paddy', 'sale_unit' => 'quintal']);
        $this->seller = Party::query()->create(['code' => 'F1', 'name' => 'Ram Singh', 'is_farmer' => true, 'is_vendor' => true, 'gst_registration_type' => 'unregistered']);
        $this->broker = Party::query()->create(['code' => 'B1', 'name' => 'Suresh (broker)', 'is_broker' => true, 'gst_registration_type' => 'unregistered']);
        $this->scale = Scale::query()->create(['code' => 'WB1', 'name' => 'Main weighbridge', 'capacity_kg' => '60000', 'resolution_kg' => '10']);
        ScaleCertificate::query()->create([
            'scale_id' => $this->scale->id, 'type' => 'stamping', 'certificate_no' => 'LM/2026/001',
            'issued_on' => now()->subMonths(2)->toDateString(), 'valid_until' => now()->addMonths(10)->toDateString(),
        ]);

        // Moisture above 17 %: 1 % weight per 1 % excess; reject above 22 %.
        DeductionRule::query()->create(['product_id' => $this->paddy->id, 'parameter' => 'moisture', 'method' => 'excess_weight', 'free_limit_bp' => 1700, 'factor' => 10000, 'reject_above_bp' => 2200]);
        // Foreign matter above 2 %: ₹10 per quintal per full 1 %.
        DeductionRule::query()->create(['product_id' => $this->paddy->id, 'parameter' => 'foreign_matter', 'method' => 'rate_cut', 'free_limit_bp' => 200, 'factor' => 1000]);
        // Bag weight 600 g per bag.
        DeductionRule::query()->create(['product_id' => $this->paddy->id, 'parameter' => 'bags', 'method' => 'per_bag', 'factor' => 600]);

        Vehicle::query()->create(['registration_no' => 'HR05AB1234', 'vehicle_type' => 'truck']);
        Make::reset();
    }

    public function as(LoginAccount $account): void
    {
        app(AuthContextBinder::class)->bind($account);
    }

    /** A confirmed purchase sauda (created by owner, approved by owner2). */
    public function confirmedSauda(string $quantityKg = '100000', int $ratePaisePerQuintal = 250000): Sauda
    {
        $this->as($this->owner);
        $sauda = app(SaudaService::class)->create([
            'party_id' => $this->seller->id, 'broker_party_id' => $this->broker->id, 'product_id' => $this->paddy->id,
            'quantity_kg' => $quantityKg, 'rate_paise_per_quintal' => $ratePaisePerQuintal,
            'delivery_from' => now()->toDateString(), 'delivery_to' => now()->addDays(30)->toDateString(),
        ]);
        app(SaudaService::class)->submit($sauda);
        $this->as($this->owner2);
        $sauda = app(SaudaService::class)->approve($sauda);
        Make::reset();

        return $sauda;
    }

    /** Stable indicator evidence around a weight. */
    public static function readings(string $kg, int $samples = 6): array
    {
        return ['samples' => array_fill(0, $samples, $kg), 'window_ms' => 3000];
    }
}
