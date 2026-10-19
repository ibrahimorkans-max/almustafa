<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Reports\ReportCatalog;
use App\Filament\Pages\AccountsReports;
use App\Filament\Pages\AllReportsPage;
use App\Filament\Pages\AttendanceRegisterPage;
use App\Filament\Pages\OperationalReportsPage;
use App\Filament\Pages\SalesTaxReturnsPage;
use App\Filament\Pages\StatutoryReportsPage;
use App\Http\Controllers\ReportPrintController;
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
    $this->from = CarbonImmutable::now('Asia/Karachi')->subDays(30)->startOfDay();
    $this->to = CarbonImmutable::now('Asia/Karachi')->startOfDay();
});

function arSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

it('builds every report of the catalogue without an error', function () {
    arSignIn($this, $this->admin);
    $catalog = app(ReportCatalog::class);

    foreach (array_keys(ReportCatalog::REPORTS) as $key) {
        $parts = $catalog->build($key, $this->from, $this->to);
        expect($parts)->not->toBeEmpty("{$key} has no parts");
        foreach ($parts as $part) {
            expect($part['title'])->not->toBe('')
                ->and(implode(' ', $part['lines']))->not->toContain('could not be made');
            foreach ($part['rows'] as $row) {
                expect(count($row))->toBe(count($part['columns']), "{$key}: a row does not match the columns");
            }
        }
    }

    // The vehicle register over the period: the demo vehicles in, with weights and the amount to pay.
    $vehicles = $catalog->build('vehicle_register', $this->from, $this->to)[0];
    expect(count($vehicles['rows']))->toBeGreaterThan(1)->and(end($vehicles['rows'])[0])->toBe('TOTAL');
});

it('prints all reports in one PDF with a contents page by category, and as one Excel workbook', function () {
    arSignIn($this, $this->admin);
    $all = array_keys(ReportCatalog::REPORTS);
    $url = ReportPrintController::url($all, $this->from->toDateString(), $this->to->toDateString());

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $html = (string) PdfRenderer::$lastHtml;
    expect($html)->toContain('ALL REPORTS')->toContain('Contents')
        ->and($html)->toContain('Accounts and finance')->toContain('Gate and vehicles')->toContain('HR and payroll')
        ->and($html)->toContain('Trial balance')->toContain('Vehicles in (paddy received)')->toContain('Checked by (second person)')
        ->and(substr_count($html, '<pagebreak'))->toBeGreaterThanOrEqual(count($all));

    $xlsx = $this->get(ReportPrintController::url($all, $this->from->toDateString(), $this->to->toDateString(), ['format' => 'xlsx']));
    $xlsx->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect(substr((string) $xlsx->getContent(), 0, 2))->toBe('PK');

    // One report alone: no contents page.
    $this->get(ReportPrintController::url(['trial_balance'], null, $this->to->toDateString()))->assertOk();
    expect(PdfRenderer::$lastHtml)->toContain('TRIAL BALANCE')->not->toContain('Contents');
});

it('refuses reports the person may not see, and bad dates', function () {
    $hr = Make::account($this->entity, 'HR_MANAGER');
    arSignIn($this, $hr);

    $this->get(ReportPrintController::url(['pl'], null, null))->assertForbidden();
    $this->get(ReportPrintController::url(['attendance'], null, null))->assertOk();
    $this->get(ReportPrintController::url(['nonsense'], null, null))->assertForbidden();
    $this->get(ReportPrintController::url(['attendance'], '2026-10-10', '2026-10-01'))->assertStatus(422);

    expect(array_keys(ReportCatalog::forUser($hr)))->toBe(['hr']);
});

it('lists the reports by category on the All reports page, ticks a category and builds the print links', function () {
    arSignIn($this, $this->admin);
    $page = Livewire::test(AllReportsPage::class)->assertOk()
        ->assertSee('Accounts and finance')->assertSee('Gate and vehicles')->assertSee('Print all selected reports ('.count(ReportCatalog::REPORTS).')')
        ->assertSee('Print category');

    $page->call('toggleCategory', 'hr');
    expect($page->get('selected'))->not->toContain('attendance', 'wages');
    $page->call('toggleCategory', 'hr');
    expect($page->get('selected'))->toContain('attendance', 'wages');

    $page->call('selectAll', false)->assertSee('Tick at least one report.');
    $page->set('selected', ['pl', 'vehicle_register']);
    expect(urldecode((string) $page->instance()->printUrl()))->toContain('r[0]=pl')->toContain('r[1]=vehicle_register');
});

it('has a PDF button on every report screen', function () {
    arSignIn($this, $this->admin);

    foreach ([OperationalReportsPage::class => 'pdf', StatutoryReportsPage::class => 'pdf', SalesTaxReturnsPage::class => 'salesPdf',
        AttendanceRegisterPage::class => 'pdf', AccountsReports::class => 'reportPdf'] as $page => $action) {
        $screen = Livewire::test($page)->assertActionVisible($action)->assertActionVisible('allReports');
        expect($screen->instance()->getAction($action)?->getUrl())->toContain('/admin/reports/print');
    }
});
