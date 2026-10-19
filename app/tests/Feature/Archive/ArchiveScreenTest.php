<?php

declare(strict_types=1);

use App\Domain\Assistant\Screens;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\VehicleRegister;
use App\Domain\Reports\OperationalReports;
use App\Domain\Reports\ReportCatalog;
use App\Domain\Settings\SettingsService;
use App\Filament\Pages\ArchiveSearch;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\Gate\GateEntryResource\Pages\ListGateEntries;
use App\Http\Controllers\ReportPrintController;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\DemoMastersSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('AMR');
    $this->seed(DemoMastersSeeder::class);
    $this->seed(DemoActivitySeeder::class);
    Make::reset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Make::account($this->entity, 'SUPER_ADMIN');
    // The first demo vehicle (exited) is archived; the second stays a working record.
    [$this->archived, $this->working] = Make::system(fn () => GateEntry::query()->where('status', 'exited')->orderBy('arrived_at')->take(2)->get()->all());
    Make::system(fn () => $this->archived->forceFill(['archived_at' => now()])->save());
});

function arcSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

it('keeps archived records out of every working screen, report and printout, with no filter to bring them back', function () {
    arcSignIn($this, $this->admin);
    $from = CarbonImmutable::now('Asia/Karachi')->subDays(30);
    $to = CarbonImmutable::now('Asia/Karachi');

    $list = Livewire::test(ListGateEntries::class)->set('tableFilters.open.value', 'all')
        ->assertCanSeeTableRecords([$this->working])->assertCanNotSeeTableRecords([$this->archived]);
    expect(array_keys($list->instance()->getTable()->getFilters()))->not->toContain('archived');
    $this->get(GateEntryResource::getUrl('view', ['record' => $this->archived]))->assertNotFound();

    [, $rows] = app(OperationalReports::class)->run('gate_register', $from, $to);
    $numbers = array_column($rows, 1);
    expect($numbers)->toContain($this->working->number)->not->toContain($this->archived->number);

    $day = CarbonImmutable::parse($this->archived->arrived_at)->timezone('Asia/Karachi');
    expect(app(VehicleRegister::class)->query($day)->pluck('number')->all())->not->toContain($this->archived->number);
    $vehicles = app(ReportCatalog::class)->build('vehicle_register', $from, $to)[0]['rows'];
    expect(array_column($vehicles, 2))->toContain($this->working->number)->not->toContain($this->archived->number);
    $this->get(route('filament.admin.vehicle-register.vehicle', ['id' => $this->archived->id]))->assertNotFound();
    $this->get(route('filament.admin.print-record', ['resource' => Screens::key(GateEntryResource::class), 'id' => $this->archived->id]))->assertNotFound();
});

it('shows archived records by period on the Archive screen, in full, and restores the whole period with a reason', function () {
    arcSignIn($this, $this->admin);
    $page = Livewire::test(ArchiveSearch::class)
        ->set('from', now('Asia/Karachi')->subDays(30)->toDateString())->set('to', now('Asia/Karachi')->toDateString())
        ->set('type', 'gate_entry')->call('search')
        ->assertSee($this->archived->number)->assertDontSee($this->working->number)
        ->assertSee('cannot be printed')->assertDontSee('Print');

    $page->mountAction('view', ['type' => 'gate_entry', 'id' => $this->archived->id])
        ->assertSee('Vehicle and driver')->assertSee('Weighments (from the weighbridge)')->assertSee('read only');

    // A period with nothing archived.
    Livewire::test(ArchiveSearch::class)->set('from', '2020-01-01')->set('to', '2020-12-31')->call('search')->assertSee('No records in this period.');

    Livewire::test(ArchiveSearch::class)
        ->set('from', now('Asia/Karachi')->subDays(30)->toDateString())->set('to', now('Asia/Karachi')->toDateString())
        ->set('type', 'gate_entry')->call('search')
        ->callAction('restorePeriod', ['reason' => 'Needed again for the buyer dispute'])->assertNotified('1 record(s) restored');
    expect(Make::system(fn () => $this->archived->fresh()->archived_at))->toBeNull();
    Livewire::test(ListGateEntries::class)->set('tableFilters.open.value', 'all')->assertCanSeeTableRecords([$this->archived->fresh()]);
});

it('opens the Archive screen for the Owner and the Super Admin only', function () {
    arcSignIn($this, Make::account($this->entity, 'ACCOUNTANT'));
    expect(ArchiveSearch::canAccess())->toBeFalse();
    $this->get('/admin/office-desk')->assertForbidden();

    arcSignIn($this, $this->admin);
    $this->get('/admin/office-desk')->assertOk();
});

it('gives the Office Coordinator the Archive screen and nothing else', function () {
    $keeper = Make::account($this->entity, 'OFFICE_COORDINATOR');
    arcSignIn($this, $keeper);

    expect(Dashboard::archiveOnly($keeper))->toBeTrue()
        ->and(Dashboard::archiveOnly($this->admin))->toBeFalse();
    $this->get('/admin')->assertRedirect(ArchiveSearch::getUrl());

    $this->get('/admin/office-desk')->assertOk()->assertSee('Show records')->assertDontSeeText('Archived')->assertDontSeeText('archived')->assertDontSeeText('Archive screen');
    $menu = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getLabel())->values()->all();
    expect($menu)->toContain('Office Desk')->and(array_diff($menu, ['Office Desk', 'Dashboard']))->toBe([]);

    foreach (['/admin/gate-entries', '/admin/saudas', '/admin/vehicle-register', '/admin/business-report', '/admin/all-reports',
        '/admin/accounts-reports', '/admin/sessions', '/admin/access-review', '/admin/assistant', '/admin/hr/employees'] as $path) {
        expect($this->get($path)->status())->toBeIn([403, 404], $path);
    }
    $this->get(ReportPrintController::url(['gate_register'], null, null))->assertForbidden();

    Livewire::test(ArchiveSearch::class)
        ->set('from', now('Asia/Karachi')->subDays(30)->toDateString())->set('to', now('Asia/Karachi')->toDateString())
        ->call('search')->assertSee($this->archived->number)
        ->callAction('restore', ['reason' => 'Brought back by the archive manager'], ['type' => 'gate_entry', 'id' => $this->archived->id])
        ->assertNotified('Restored');
});

it('lets the Owner and the Office Coordinator choose after how many months closed records move to the Office Desk', function () {
    $settings = app(SettingsService::class);
    expect($settings->archiveRetentionMonths())->toBe((int) config('ricemill.archive.retention_months'));

    arcSignIn($this, Make::owner($this->entity));
    Livewire::test(ArchiveSearch::class)->assertSee('When closed records move here')
        ->set('retention', 12)->call('saveRetention')->assertNotified();
    expect($settings->archiveRetentionMonths())->toBe(12)
        ->and(AuditLog::query()->where('action', 'settings.archive_retention_changed')->exists())->toBeTrue();

    // Fewer months than offered is refused.
    Livewire::test(ArchiveSearch::class)->set('retention', 1)->call('saveRetention')->assertHasErrors('retention');
    expect($settings->archiveRetentionMonths())->toBe(12);

    // The monthly run uses the chosen period.
    $this->artisan('archive:run --dry-run')->expectsOutputToContain('Closed before '.now()->subMonths(12)->toDateString())->assertSuccessful();

    // The Office Coordinator sets it too; the mill's Super Admin does not see or change it.
    arcSignIn($this, Make::account($this->entity, 'OFFICE_COORDINATOR'));
    Livewire::test(ArchiveSearch::class)->assertSee('When closed records move here')->set('retention', 6)->call('saveRetention')->assertNotified();
    expect($settings->archiveRetentionMonths())->toBe(6);

    arcSignIn($this, $this->admin);
    Livewire::test(ArchiveSearch::class)->assertDontSee('When closed records move here')->call('saveRetention')->assertForbidden();
});

it('locks the account everywhere with the panic button when a device is lost or stolen', function () {
    $coordinator = Make::account($this->entity, 'OFFICE_COORDINATOR', ['mfa' => true]);
    // An open session on a phone that has been stolen, and another browser session elsewhere.
    $phone = Make::trustedDevice($coordinator, 'stolen-phone-0001', 'android');
    $phoneSession = Make::system(fn () => AuthSession::query()->create([
        'entity_id' => $coordinator->entity_id, 'login_account_id' => $coordinator->id, 'device_id' => $phone->id, 'channel' => 'api',
        'ip' => '203.0.113.7', 'expires_at' => now()->addHours(8), 'last_seen_at' => now(),
    ]));
    $elsewhere = Make::webSession($coordinator);
    arcSignIn($this, $coordinator);

    Livewire::test(ArchiveSearch::class)->assertActionVisible('panic')
        ->callAction('panic', ['note' => 'Phone taken from the office'])
        ->assertRedirect(Filament::getLoginUrl());

    $account = Make::system(fn () => $coordinator->fresh());
    expect($account->needs_admin_unlock)->toBeTrue()->and($account->isLocked())->toBeTrue()
        ->and(Make::system(fn () => $phone->fresh()->status))->toBe(Device::STATUS_WIPE_PENDING)
        ->and(Make::system(fn () => $phoneSession->fresh()->revoked_at))->toBeNull() // kept only to deliver the wipe
        ->and(Make::system(fn () => AuthSession::query()->find($elsewhere[EnsureWebAuthSession::SESSION_KEY])->revoked_at))->not->toBeNull()
        ->and(Make::system(fn () => AuditLog::query()->where('action', 'security.panic_button')->sole()->meta['note']))->toBe('Phone taken from the office');

    // The Owner has no panic button here (nobody above the Owner could unlock the account).
    arcSignIn($this, Make::owner($this->entity));
    Livewire::test(ArchiveSearch::class)->assertActionHidden('panic');
});
