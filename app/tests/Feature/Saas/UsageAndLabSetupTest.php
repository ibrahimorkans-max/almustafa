<?php

declare(strict_types=1);

use App\Domain\Access\RoleTemplates;
use App\Domain\Gate\GateService;
use App\Domain\Gate\LabSetup;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Usage\UsageContext;
use App\Domain\Usage\UsageMeter;
use App\Domain\Usage\UsageReport;
use App\Filament\Pages\Platform\PlatformUsersPage;
use App\Filament\Pages\Platform\ServicePricesPage;
use App\Filament\Pages\Platform\UsageCostsPage;
use App\Filament\Pages\ServiceUsagePage;
use App\Models\Gate\LabParameter;
use App\Models\Role;
use App\Models\Usage\ServicePrice;
use App\Models\Usage\ServiceUsage;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('USG'));
    $this->platform = Make::platformAdmin(Make::entity('PLT'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->f->as($this->f->owner);
});

function usgWhatsApp(string $to, string $wamid): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => $wamid]]])]);
    Http::post('https://graph.facebook.com/v21.0/123456789/messages', ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => 'mill_update']]);
}

describe('usage records', function () {
    it('records each outside call by itself — who, which mill, what for, when, cost — once, and never changes it', function () {
        Make::system(fn () => ServicePrice::query()->create(['channel' => 'whatsapp', 'provider' => '*', 'unit_price' => '2.50', 'currency' => 'PKR', 'effective_from' => now()->subDay()]));
        UsageContext::run(['module' => 'Weighbridge Management', 'action' => 'Weight slip notification', 'description' => 'Told the supplier the weighing is complete',
            'related_type' => 'gate_entry', 'related_id' => 'GE-1'], fn () => usgWhatsApp('923001234567', 'wamid.ABC'));
        usgWhatsApp('923001234567', 'wamid.ABC');   // a retry with the same message id: not counted again

        $u = ServiceUsage::query()->sole();
        expect($u->channel)->toBe('whatsapp')->and($u->entity_id)->toBe($this->f->entity->id)->and($u->login_account_id)->toBe($this->f->owner->id)
            ->and($u->module)->toBe('Weighbridge Management')->and($u->action)->toBe('Weight slip notification')->and($u->related_id)->toBe('GE-1')
            ->and($u->recipient_masked)->toBe('9230******67')->and($u->template)->toBe('mill_update')
            ->and($u->estimated_cost)->toBe('2.500000')->and($u->reference)->toStartWith('U-')
            ->and(fn () => Make::system(fn () => DB::transaction(fn () => DB::table('service_usages')->update(['quantity' => 9]))))->toThrow(QueryException::class);

        UsageMeter::update('wamid.ABC', 'delivery', 'delivered');
        expect($u->fresh()->deliveryStatus())->toBe('delivered');
    });

    it('records e-mails and AI calls with the cost the provider reports', function () {
        Mail::raw('Your statement', fn ($m) => $m->to('ali.traders@example.com')->subject('Statement'));
        Http::fake(['openrouter.ai/*' => Http::response(['id' => 'gen-1', 'model' => 'x', 'usage' => ['total_tokens' => 321, 'cost' => 0.0042]])]);
        Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'anthropic/claude', 'messages' => []]);

        $mail = ServiceUsage::query()->where('channel', 'email')->sole();
        $ai = ServiceUsage::query()->where('category', 'ai')->sole();
        expect($mail->recipient_masked)->toBe('a***@example.com')
            ->and($ai->units)->toBe(321)->and($ai->actual_cost)->toBe('0.004200')->and($ai->actual_currency)->toBe('USD');
    });

    it('costs by the price list: free allowance, then the unit price, then the cheaper tier', function () {
        Make::system(fn () => ServicePrice::query()->create(['channel' => 'sms', 'provider' => '*', 'unit_price' => '1.00', 'currency' => 'PKR',
            'free_allowance' => 2, 'tiers' => [['from' => 4, 'price' => '0.50']], 'effective_from' => now()->subDay()]));
        foreach (range(1, 5) as $i) {
            UsageMeter::record(['channel' => 'sms', 'category' => 'sms', 'provider' => 'Twilio', 'service' => 'SMS', 'success' => true, 'key' => "sms-{$i}", 'recipient' => '03001234567']);
        }
        $charges = ServiceUsage::query()->orderBy('reference')->pluck('charge')->map(fn ($c) => (float) $c)->all();
        expect($charges)->toBe([0.0, 0.0, 1.0, 0.5, 0.5]);

        $s = UsageReport::issueStatement($this->f->entity->customer_id, CarbonImmutable::now('Asia/Karachi'), $this->platform->id);
        expect((float) $s->total)->toBe(2.0);
    });

    it("shows a mill only its own company's usage; the SaaS owner sees all", function () {
        UsageMeter::record(['channel' => 'api', 'category' => 'ai', 'provider' => 'OpenAI', 'service' => 'Chat', 'success' => true, 'key' => 'mine']);
        $other = Make::system(fn () => app(\App\Domain\Access\EntityProvisioner::class)->create(['code' => 'OTH', 'name' => 'Other mill', 'status' => 'active', 'customer_id' => Make::customer('OTHER')->id]));
        app(TenantContext::class)->runInEntity($other->id, fn () => UsageMeter::record(['channel' => 'api', 'category' => 'ai', 'provider' => 'OpenAI', 'service' => 'Chat', 'success' => true, 'key' => 'theirs']));

        $mill = new UsageReport(['period' => 'today', 'customer' => $this->f->entity->customer_id], false);
        $all = new UsageReport(['period' => 'today'], true);
        expect((int) $mill->totals()['calls'])->toBe(1)->and((int) $all->totals()['calls'])->toBe(2)
            ->and(ServiceUsage::query()->count())->toBe(1);   // row security, whatever the screen asks
    });

    it('opens the usage screens to the right people only', function () {
        $this->actingAs($this->platform, 'web')->withSession(Make::webSession($this->platform));
        $this->f->as($this->platform);
        Livewire::test(UsageCostsPage::class)->assertOk()->assertSee('Usage records');
        Livewire::test(ServicePricesPage::class)->assertOk()->assertSee('Company statements');
        Livewire::test(PlatformUsersPage::class)->assertOk()->assertSee($this->f->owner->username);

        $this->actingAs($this->f->owner, 'web')->withSession(Make::webSession($this->f->owner));
        $this->f->as($this->f->owner);
        expect(UsageCostsPage::canAccess())->toBeFalse()->and(PlatformUsersPage::canAccess())->toBeFalse()->and(ServiceUsagePage::canAccess())->toBeTrue();
        Livewire::test(ServiceUsagePage::class)->assertOk();
    });

    it('has a Report Viewer role that sees and changes nothing', function () {
        $perms = RoleTemplates::get(RoleTemplates::REPORT_VIEWER)['permissions'];
        expect($perms)->toContain('report.view', 'stock.view', 'usage.view')->not->toContain('user.manage', 'gate.entry', 'audit.view', 'settings.manage');
    });
});

describe('lab setup', function () {
    beforeEach(function () {
        $this->gate = app(GateService::class);
        $this->moisture = LabParameter::query()->where('code', 'moisture')->whereNull('product_id')->first();
    });

    it('needs every mandatory test before accepting, and counts a result within tolerance as standard', function () {
        $this->moisture->forceFill(['tolerance_bp' => 50])->save();
        LabParameter::query()->where('code', 'broken')->update(['mandatory' => true]);
        $e = $this->gate->arrive(['registration_no' => 'MNA 21 1234']);
        $this->gate->recordLabTest($e, ['results' => ['moisture' => $this->moisture->standard_bp + 40]]);
        expect(collect(\App\Models\Gate\LabTest::query()->where('gate_entry_id', $e->id)->first()->evaluation)->firstWhere('code', 'moisture')['verdict'])->toBe('ok')
            ->and(fn () => $this->gate->decideLab($e->fresh(), true))->toThrow(BusinessRuleViolation::class, 'Mandatory');
    });

    it('refuses a late result where the mill says so, and lets the SaaS owner switch lab features off', function () {
        DB::table('lab_time_rules')->insert(['id' => (string) Str::uuid(), 'entity_id' => $this->f->entity->id, 'minutes' => 30, 'starts_at_event' => 'arrived',
            'ends_at_event' => 'lab_result', 'late_rule' => 'block', 'alert_on_expiry' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $e = $this->gate->arrive(['registration_no' => 'MNA 21 1234'], null, now()->subHour());
        expect(fn () => $this->gate->recordLabTest($e, ['results' => ['moisture' => 1300]]))->toThrow(BusinessRuleViolation::class, 'not accepted')
            ->and(LabSetup::alertExpired())->toBe(1);

        $this->f->as($this->platform);
        LabSetup::configure($this->f->entity->customer_id, null, ['custom_tests' => true, 'deductions' => false, 'time_grace' => false, 'negotiation' => false]);
        $this->f->as($this->f->owner);
        expect(LabSetup::allows($this->f->entity->id, 'time_grace'))->toBeFalse();
        $this->gate->recordLabTest($e->fresh(), ['results' => ['moisture' => 1300]]);   // no time rule now
        expect(fn () => $this->gate->openNegotiation($e->fresh(), 'try a price'))->toThrow(BusinessRuleViolation::class, 'Negotiation is not switched on');
    });
});
