<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\DailyWages\DailyWages;
use App\Domain\Gate\GateService;
use App\Domain\Gate\WeighmentChecks;
use App\Domain\Procurement\SaudaService;
use App\Domain\Tenancy\TenantContext;
use App\Models\DailyWages\DailyWageCategory;
use App\Models\DailyWages\DailyWageGroup;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use App\Models\Gate\WeighmentCheck;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use App\Models\Person;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * Local/demo activity on top of DemoMastersSeeder, so every screen has something to try:
 * two confirmed paddy saudas; trucks at every gate stage (finished and gone, at the lab, unloaded
 * and waiting for tare, just arrived and waiting for gross); recorded weights that are checked OK,
 * queried, or not checked yet. Live readings for the Live weight screen come from
 * `php artisan ricemill:demo-live-weight`.
 *
 * The work is done through the real services by three demo staff logins ("demo.clerk",
 * "demo.manager1", "demo.manager2"), so numbers, flags, stock and audit are as in real use. Their
 * passwords are random and not saved: nobody signs in with them. Your own accounts can check the
 * weights they recorded. Refuses to run in production; runs once per mill.
 */
class DemoActivitySeeder extends Seeder
{
    public function run(TenantContext $tenant): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoActivitySeeder must not run in production.');
        }

        foreach ($tenant->runAsSystem(fn () => Entity::query()->where('code', 'AMR')->get()) as $entity) {
            if ($tenant->runAsSystem(fn () => LoginAccount::query()->where('username', strtolower($entity->code).'.demo.clerk')->exists())) {
                $this->command?->info("Demo activity for {$entity->code} already exists; skipped.");
            } else {
                $this->seedEntity($tenant, $entity);
                $this->command?->info("Demo activity for {$entity->code}: saudas, gate entries at every stage, weights to check.");
            }
            // Added later: also on databases seeded before daily wages existed.
            if (! $tenant->runAsSystem(fn () => DailyWageCategory::query()->where('entity_id', $entity->id)->exists())) {
                $this->seedDailyWages($entity);
                $this->command?->info("Demo daily wages for {$entity->code}: work categories, labour groups, wage sheets.");
            }
        }
    }

    /** Three kinds of casual work, two labour groups, and wage sheets that are paid, owed and draft. */
    private function seedDailyWages(Entity $entity): void
    {
        $manager = app(TenantContext::class)->runAsSystem(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])
            ->where('username', strtolower($entity->code).'.demo.manager1')->firstOrFail());
        app(AuthContextBinder::class)->bind($manager);

        $loading = DailyWageCategory::query()->create(['code' => 'LOADING', 'name' => 'Loading / unloading', 'unit' => 'day', 'rate_paise' => 1_500_00]);
        $stitching = DailyWageCategory::query()->create(['code' => 'STITCH', 'name' => 'Bag stitching', 'unit' => 'bag', 'rate_paise' => 6_00]);
        $cleaning = DailyWageCategory::query()->create(['code' => 'CLEAN', 'name' => 'Cleaning the mill yard', 'unit' => 'day', 'rate_paise' => 1_200_00]);
        $akram = DailyWageGroup::query()->create(['name' => 'Akram loading group', 'leader_name' => 'Muhammad Akram (demo)', 'leader_phone' => '0301-5551234',
            'default_category_id' => $loading->id, 'usual_size' => 12]);
        $rafiq = DailyWageGroup::query()->create(['name' => 'Rafiq stitching group', 'leader_name' => 'Rafiq Ahmad (demo)', 'leader_phone' => '0302-5556789',
            'default_category_id' => $stitching->id, 'usual_size' => 4]);

        $wages = app(DailyWages::class);
        $cash = app(ChartOfAccounts::class)->system('cash');
        $yesterday = now('Asia/Karachi')->subDay()->toDateString();
        $wages->post($wages->record(['work_date' => $yesterday, 'group_id' => $akram->id, 'category_id' => $loading->id, 'workers' => 12, 'quantity' => '1',
            'extra_paise' => 1_200_00, 'description' => 'Demo: unloaded 6 paddy trucks, worked late']), $cash);
        $wages->post($wages->record(['work_date' => now('Asia/Karachi')->toDateString(), 'group_id' => $rafiq->id, 'category_id' => $stitching->id,
            'workers' => 4, 'quantity' => '800', 'description' => 'Demo: 800 rice bags stitched']), null);
        $wages->record(['work_date' => now('Asia/Karachi')->toDateString(), 'worker_name' => 'Allah Ditta (demo)', 'category_id' => $cleaning->id, 'workers' => 1, 'quantity' => '1']);

        app(TenantContext::class)->clear();
        app(ActorContext::class)->clear();
    }

    private function seedEntity(TenantContext $tenant, Entity $entity): void
    {
        $p = strtolower($entity->code);
        [$clerk, $manager1, $manager2] = $tenant->runAsSystem(fn () => [
            $this->staff($entity, 'SUPERVISOR', "{$p}.demo.clerk", 'Demo gate clerk'),
            $this->staff($entity, 'SUPER_ADMIN', "{$p}.demo.manager1", 'Demo manager 1'),
            $this->staff($entity, 'SUPER_ADMIN', "{$p}.demo.manager2", 'Demo manager 2'),
        ]);
        $bind = fn (LoginAccount $a) => app(AuthContextBinder::class)->bind($a);

        $bind($manager1);
        $paddy = Product::query()->where('code', 'PDY-SUPER')->firstOrFail();
        $paddy1121 = Product::query()->where('code', 'PDY-1121')->firstOrFail();
        $farmer = Party::query()->where('code', "{$entity->code}-F001")->firstOrFail();
        $arhti = Party::query()->where('code', "{$entity->code}-V001")->firstOrFail();
        $broker = Party::query()->where('code', "{$entity->code}-B001")->first();
        $scale = Scale::query()->where('code', 'WB1')->firstOrFail();
        $godown = Godown::query()->where('code', 'G1')->firstOrFail();

        $saudas = [];
        foreach ([[$farmer, $paddy, '200000', 420000], [$arhti, $paddy1121, '150000', 455000]] as [$party, $product, $kg, $rate]) {
            $bind($manager1);
            $sauda = app(SaudaService::class)->create([
                'party_id' => $party->id, 'broker_party_id' => $broker?->id, 'product_id' => $product->id,
                'quantity_kg' => $kg, 'rate_paise_per_maund' => $rate,
                'delivery_from' => now()->subDays(10)->toDateString(), 'delivery_to' => now()->addDays(30)->toDateString(),
            ]);
            app(SaudaService::class)->submit($sauda);
            $bind($manager2);
            $saudas[] = app(SaudaService::class)->approve($sauda, 'Demo deal');
        }

        $gate = app(GateService::class);
        $bind($clerk);
        $known = Vehicle::query()->where('is_blocked', false)->orderBy('registration_no')->pluck('registration_no')->all();
        $plates = array_merge($known, ['MNA-23-4411', 'LRA-7788', 'MNC-19-2025', 'BWN-5512']);

        // Three trucks: lab tested, accepted, weighed loaded, unloaded, weighed empty, Caat and price settled
        // (proposed by the clerk, approved by a manager), slip issued, left.
        $done = [];
        foreach ([[6, '25480', '10230', 1350, '0.5'], [4, '31260', '11900', 1550, '1'], [2, '18940', '7660', 1400, '0']] as $i => [$daysAgo, $gross, $tare, $moisture, $perMaund]) {
            $at = CarbonImmutable::now()->subDays($daysAgo)->setTime(9 + $i, 15);
            $e = $gate->arrive(['registration_no' => $plates[$i], 'sauda_id' => $saudas[$i % 2]->id, 'bag_count' => (int) round(((int) $gross - (int) $tare) / 40)], null, $at);
            $gate->recordLabTest($e, ['results' => $this->labResults($moisture), 'grade' => 'A', 'sample_code' => 'S-'.($i + 1)], $at->addMinutes(10));
            $gate->decideLab($e, true, 'Demo: accepted', $at->addMinutes(15));
            $this->weigh($gate, $e, 'gross', $gross, $scale, $at->addMinutes(20));
            $gate->markUnloaded($e, $at->addMinutes(70), true, $godown);
            $this->weigh($gate, $e, 'tare', $tare, $scale, $at->addMinutes(80));
            $gate->proposeSettlement($e, (string) ($gate->project($e->fresh())->deduction_kg ?? '0'), $perMaund, 'Demo settlement');
            $bind($manager1);
            $gate->approveSettlement($e, 'Demo: approved');
            $bind($clerk);
            $gate->issueSlip($e);
            $gate->exit($e, $at->addMinutes(90));
            $done[] = $e;
        }

        // At the lab: arrived, waiting for the test (no weighing before the lab decides).
        $gate->arrive(['registration_no' => $plates[3], 'sauda_id' => $saudas[0]->id, 'bag_count' => 420], null, now()->subHours(2));

        // Accepted, weighed loaded, unloaded, waiting for the empty (tare) weight.
        $unloaded = $gate->arrive(['registration_no' => $plates[4], 'sauda_id' => $saudas[1]->id, 'bag_count' => 380], null, now()->subHours(1));
        $gate->recordLabTest($unloaded, ['results' => $this->labResults(1580), 'grade' => 'A'], now()->subMinutes(55));
        $gate->decideLab($unloaded, true, 'Demo: accepted (moisture within grace)', now()->subMinutes(50));
        $this->weigh($gate, $unloaded, 'gross', '24320', $scale, CarbonImmutable::now()->subMinutes(45));
        $gate->markUnloaded($unloaded, now()->subMinutes(10), true, $godown);

        // Accepted by the lab, waiting for the gross weight (use it with ricemill:demo-live-weight).
        $waiting = $gate->arrive(['registration_no' => $plates[5], 'sauda_id' => $saudas[0]->id, 'bag_count' => 400], null, now()->subMinutes(20));
        $gate->recordLabTest($waiting, ['results' => $this->labResults(1380), 'grade' => 'A'], now()->subMinutes(12));
        $gate->decideLab($waiting, true, 'Demo: accepted', now()->subMinutes(8));

        // Weight checks: the first finished truck OK, the second queried, the rest not checked yet.
        $bind($manager1);
        $weights = Weighment::query()->whereIn('gate_entry_id', [$done[0]->id, $done[1]->id])->orderBy('captured_at')->get();
        foreach ($weights as $w) {
            $queried = $w->gate_entry_id === $done[1]->id && $w->kind === Weighment::TARE;
            app(WeighmentChecks::class)->decide($w, $queried ? WeighmentCheck::QUERY : WeighmentCheck::OK,
                $queried ? 'Demo: tare 600 kg above this truck\'s usual; ask the driver about the spare wheel and tools' : 'Demo: matches the slip');
        }

        app(TenantContext::class)->clear();
        app(ActorContext::class)->clear();
    }

    /**
     * Demo results for the seven lab tests (basis points), all within their starting limits except as given.
     *
     * @return array<string, int>
     */
    private function labResults(int $moistureBp): array
    {
        return ['moisture' => $moistureBp, 'green_grain' => 250, 'shell' => 150, 'shewad_green' => 200, 'broken' => 450, 'red_rice' => 120, 'dandly' => 60];
    }

    /** A weight from the indicator with a settled reading window as its evidence (no camera photo in the demo). */
    private function weigh(GateService $gate, GateEntry $e, string $kind, string $kg, Scale $scale, CarbonImmutable $at): Weighment
    {
        $samples = array_fill(0, 6, number_format((float) $kg, 3, '.', ''));

        return $gate->recordWeighment($e, $kind, $kg, $scale, ['samples' => $samples, 'window_ms' => 3000], null, $at);
    }

    /** A demo staff login that can act now (no password change or 2FA pending); its password is never shown. */
    private function staff(Entity $entity, string $roleCode, string $username, string $name): LoginAccount
    {
        $role = Role::query()->where('entity_id', $entity->id)->where('code', $roleCode)->firstOrFail();
        $person = new Person(['full_name' => $name]);
        $person->created_in_entity_id = $entity->id;
        $person->save();

        $account = LoginAccount::query()->create([
            'entity_id' => $entity->id, 'person_id' => $person->id, 'role_id' => $role->id, 'username' => $username,
            'password' => Str::password(32), 'status' => LoginAccount::STATUS_ACTIVE, 'must_change_password' => false,
        ]);
        if ($role->mfa_required) {
            $account->forceFill(['mfa_secret' => app(Google2FA::class)->generateSecretKey(32), 'mfa_confirmed_at' => now()])->save();
        }

        return $account->fresh(['role', 'person', 'entity']);
    }
}
