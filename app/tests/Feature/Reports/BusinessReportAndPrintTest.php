<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Reports\BusinessReport;
use App\Filament\Pages\BusinessReportPage;
use App\Filament\Support\PrintButton;
use App\Models\Accounts\LedgerAccount;
use App\Models\LoginAccount;
use App\Models\Procurement\Sauda;
use App\Support\Export\XlsxWorkbook;
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
});

function brSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

it('builds the complete business report for a period, without the audit log', function () {
    brSignIn($this, $this->admin);
    $report = app(BusinessReport::class)->build(CarbonImmutable::now('Asia/Karachi')->subDays(30)->startOfDay(), CarbonImmutable::now('Asia/Karachi')->startOfDay());

    $headline = collect($report['headline'])->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
    $titles = array_column($report['sections'], 'title');
    expect($headline['Vehicles in (inbound)'])->toStartWith('6 ·')
        ->and($headline->keys()->contains(fn ($k) => in_array($k, ['Net PROFIT', 'Net LOSS'], true)))->toBeTrue()
        ->and($headline['Stock value at '.now('Asia/Karachi')->format('d M Y')])->toStartWith('Rs ')
        ->and($titles)->toContain('Profit and loss', 'Expenses and other income entered', 'Vehicles in (inbound)', 'Vehicles out (outbound)', 'Payroll cost by department')
        ->and(implode(' ', $titles))->not->toContain('Audit');
});

it('downloads the report as PDF and as an Excel workbook in one click', function () {
    brSignIn($this, $this->admin);
    Livewire::test(BusinessReportPage::class)->assertOk()->assertSee('Key figures')->assertSee('Vehicles in (inbound)')
        ->call('downloadPdf')->assertFileDownloaded();
    expect(PdfRenderer::$lastHtml)->toContain('COMPLETE BUSINESS REPORT')->toContain('Checked by (second person)');

    Livewire::test(BusinessReportPage::class)->set('period', 'custom')->set('from', now()->subDays(10)->toDateString())->set('to', now()->toDateString())
        ->call('downloadExcel')->assertFileDownloaded();
});

it('writes a workbook Excel can open: one sheet per part, money as numbers', function () {
    $bytes = (new XlsxWorkbook)->addSheet('Summary', ['Figure', 'Value'], [['Sales', 'Rs 1,25,000.00']])
        ->addSheet('Payments', ['Who', 'Amount'], [['Haji Riaz', 125000.5]], [1])->toString();
    $path = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $book = (string) $zip->getFromName('xl/workbook.xml');
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
    $zip->close();
    @unlink($path);

    expect($book)->toContain('name="Summary"')->toContain('name="Payments"')
        ->and($sheet)->toContain('<v>125000.5</v>');
});

it('keeps the report to people who may see the books', function () {
    brSignIn($this, Make::account($this->entity, 'GATE_OPERATOR'));
    $this->get(BusinessReportPage::getUrl())->assertForbidden();
});

it('prints a sauda contract with the maker and the second person who approved it', function () {
    brSignIn($this, $this->admin);
    $sauda = Sauda::query()->orderBy('number')->firstOrFail();

    $this->get(PrintButton::url('sauda', $sauda))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(PdfRenderer::$lastHtml)->toContain('PURCHASE CONTRACT (SAUDA)')->toContain($sauda->number)
        ->toContain('Approved by (second person)')->toContain('Demo manager 2')->toContain('Demo manager 1');

    brSignIn($this, Make::account($this->entity, 'GATE_OPERATOR'));
    $this->get(PrintButton::url('sauda', $sauda))->assertForbidden();
});

it('prints any accounting voucher with a box for the second person', function () {
    brSignIn($this, $this->admin);
    $chart = app(ChartOfAccounts::class);
    $bank = LedgerAccount::query()->where('code', 'BANK-MAIN')->firstOrFail();
    $voucher = app(Books::class)->post('journal', now(), [['account' => $bank, 'dr' => 12_500_000], ['account' => $chart->system('cash'), 'cr' => 12_500_000]], 'Cash deposited in bank');

    $this->get(PrintButton::url('voucher', $voucher))->assertOk();
    expect(PdfRenderer::$lastHtml)->toContain('JOURNAL VOUCHER')->toContain('Rs 1,25,000.00')->toContain('Checked by (second person)');
});
