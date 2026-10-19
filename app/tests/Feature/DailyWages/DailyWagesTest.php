<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\FinancialReports;
use App\Domain\Assistant\Assistant;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\DailyWages\DailyWages;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Reports\BusinessReport;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\DailyWages\DailyWageGroupResource;
use App\Filament\Resources\DailyWages\DailyWageSheetResource;
use App\Filament\Resources\DailyWages\DailyWageSheetResource\Pages\CreateDailyWageSheet;
use App\Filament\Resources\DailyWages\DailyWageSheetResource\Pages\ListDailyWageSheets;
use App\Filament\Support\PrintButton;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\VoucherLine;
use App\Models\DailyWages\DailyWageCategory;
use App\Models\DailyWages\DailyWageGroup;
use App\Models\DailyWages\DailyWageSheet;
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
    $this->accountant = Make::account($this->entity, 'ACCOUNTANT');
    $this->supervisor = Make::account($this->entity, 'SUPERVISOR');
    dwAs($this, $this->admin);
    $this->loading = DailyWageCategory::query()->where('code', 'LOADING')->sole();
    $this->stitching = DailyWageCategory::query()->where('code', 'STITCH')->sole();
    $this->akram = DailyWageGroup::query()->where('leader_name', 'like', 'Muhammad Akram%')->sole();
    $this->wages = app(DailyWages::class);
});

function dwAs(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

function dwLines(DailyWageSheet $s, ?string $voucherId = null): array
{
    return VoucherLine::query()->with('account')->where('voucher_id', $voucherId ?? $s->voucher_id)->orderBy('line_no')->get()
        ->map(fn (VoucherLine $l) => [$l->account->code, $l->dr_paise, $l->cr_paise])->all();
}

it('works out the pay per person per day, or for the whole piece work', function () {
    expect(DailyWages::gross($this->loading, 12, '1', 1_500_00))->toBe(18_000_00)       // 12 × 1 day × 1,500
        ->and(DailyWages::gross($this->loading, 3, '1.5', 1_500_00))->toBe(6_750_00)    // 3 × 1½ days
        ->and(DailyWages::gross($this->stitching, 4, '800', 6_00))->toBe(4_800_00);     // 800 bags × 6, shared by the group
});

it('books a sheet paid in cash as a direct expense', function () {
    $s = $this->wages->record(['work_date' => now()->toDateString(), 'group_id' => $this->akram->id, 'category_id' => $this->loading->id,
        'workers' => 10, 'quantity' => '1', 'extra_paise' => 500_00, 'deduction_paise' => 1_000_00]);
    expect($s->status)->toBe('draft')->and($s->net_paise)->toBe(14_500_00)->and($s->number)->toContain('/DW/');

    $s = $this->wages->post($s, app(ChartOfAccounts::class)->system('cash'));
    expect($s->status)->toBe(DailyWageSheet::PAID)
        ->and(dwLines($s))->toBe([['EXP-DAILY-WAGES', 14_500_00, 0], ['CASH', 0, 14_500_00]]);

    $pl = app(FinancialReports::class)->profitAndLoss(CarbonImmutable::now('Asia/Karachi')->startOfMonth(), CarbonImmutable::now('Asia/Karachi')->startOfDay());
    expect(collect($pl['direct_expenses']['lines'])->firstWhere('code', 'EXP-DAILY-WAGES')['amount'])->toBeGreaterThanOrEqual(14_500_00);
});

it('books a sheet as owed, then pays it from the bank', function () {
    $owedBefore = $this->wages->owed();
    $s = $this->wages->post($this->wages->record(['work_date' => now()->toDateString(), 'worker_name' => 'Nazir', 'category_id' => $this->loading->id,
        'workers' => 1, 'quantity' => '2']), null);
    expect($s->status)->toBe(DailyWageSheet::POSTED)->and(dwLines($s))->toBe([['EXP-DAILY-WAGES', 3_000_00, 0], ['DW-PAYABLE', 0, 3_000_00]])
        ->and($this->wages->owed())->toBe($owedBefore + 3_000_00);

    $bank = LedgerAccount::query()->where('code', 'BANK-MAIN')->sole();
    $s = $this->wages->pay($s, $bank);
    expect($s->status)->toBe(DailyWageSheet::PAID)->and(dwLines($s, $s->payment_voucher_id))->toBe([['DW-PAYABLE', 3_000_00, 0], ['BANK-MAIN', 0, 3_000_00]])
        ->and($this->wages->owed())->toBe($owedBefore);
});

it('refuses sheets that do not add up, and only cancels drafts', function () {
    $base = ['work_date' => now()->toDateString(), 'category_id' => $this->loading->id, 'workers' => 2, 'quantity' => '1'];
    expect(fn () => $this->wages->record($base))->toThrow(BusinessRuleViolation::class, 'labour group')
        ->and(fn () => $this->wages->record(['worker_name' => 'A', 'work_date' => now()->addDay()->toDateString()] + $base))->toThrow(BusinessRuleViolation::class, 'future')
        ->and(fn () => $this->wages->record(['worker_name' => 'A', 'deduction_paise' => 5_000_00] + $base))->toThrow(BusinessRuleViolation::class, 'deduction')
        ->and(fn () => $this->wages->record(['worker_name' => 'A', 'workers' => 0] + $base))->toThrow(BusinessRuleViolation::class, 'between 1 and 500');

    $draft = $this->wages->record($base + ['worker_name' => 'A']);
    expect($this->wages->cancel($draft, 'Entered twice')->status)->toBe('cancelled');
    $paid = $this->wages->post($this->wages->record($base + ['worker_name' => 'B']), app(ChartOfAccounts::class)->system('cash'));
    expect(fn () => $this->wages->cancel($paid, 'Oops'))->toThrow(BusinessRuleViolation::class, 'Only a draft');
});

it('lets supervisors enter wages, accountants pay them, and keeps others out', function () {
    dwAs($this, $this->supervisor);
    $s = $this->wages->record(['work_date' => now()->toDateString(), 'group_id' => $this->akram->id, 'category_id' => $this->loading->id, 'workers' => 5, 'quantity' => '1']);
    expect(fn () => $this->wages->post($s, null))->toThrow(BusinessRuleViolation::class, 'wages.daily.pay');

    dwAs($this, $this->accountant);
    expect($this->wages->post($s, null)->status)->toBe(DailyWageSheet::POSTED);

    dwAs($this, Make::account($this->entity, 'GATE_OPERATOR'));
    $this->get(DailyWageSheetResource::getUrl())->assertForbidden();
    $this->get(DailyWageGroupResource::getUrl())->assertForbidden();
});

it('enters wages on the screen: the group fills its usual work and size, the total is shown, paid at once', function () {
    $cash = app(ChartOfAccounts::class)->system('cash');
    Livewire::test(CreateDailyWageSheet::class)
        ->fillForm(['who' => 'group', 'group_id' => $this->akram->id])
        ->assertFormSet(['workers' => 12, 'category_id' => $this->loading->id, 'rate' => '1500.00'])
        ->fillForm(['workers' => 10, 'quantity' => '1', 'pay' => 'cash', 'paid_from' => $cash->id])
        ->assertSee('Rs 15,000.00')
        ->call('create')->assertHasNoFormErrors();

    $s = DailyWageSheet::query()->where('net_paise', 15_000_00)->latest('created_at')->firstOrFail();
    expect($s->status)->toBe(DailyWageSheet::PAID)->and($s->group_id)->toBe($this->akram->id);

    Livewire::test(ListDailyWageSheets::class)->assertSee('Still owed to groups')->assertCanSeeTableRecords([$s])
        ->callTableAction('pay', DailyWageSheet::query()->where('status', DailyWageSheet::POSTED)->firstOrFail(), ['paid_from' => $cash->id])
        ->assertNotified();
    expect(DailyWageSheet::query()->where('status', DailyWageSheet::POSTED)->count())->toBe(0);
});

it('prints the wage slip with the leader\'s receipt and the second person', function () {
    $s = DailyWageSheet::query()->where('status', DailyWageSheet::PAID)->firstOrFail();
    $this->get(PrintButton::url('daily_wage', $s))->assertOk();
    expect(PdfRenderer::$lastHtml)->toContain('DAILY WAGE SLIP')->toContain('Received by (group leader)')->toContain('Muhammad Akram (demo)')
        ->toContain('Checked &amp; paid by (second person)')->toContain('Rs 19,200.00');
});

it('shows daily wages in the complete business report', function () {
    $report = app(BusinessReport::class)->build(CarbonImmutable::now('Asia/Karachi')->subDays(7)->startOfDay(), CarbonImmutable::now('Asia/Karachi')->startOfDay());
    $headline = collect($report['headline'])->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
    $section = collect($report['sections'])->firstWhere('title', 'Daily wages (casual labour)');

    expect($headline['Daily wages (casual labour, in expenses)'])->toContain('Rs 24,000.00')->toContain('still owed Rs 4,800.00')
        ->and($section['rows'])->toHaveCount(2);
});

it('records daily wages from the chatbot after Confirm', function () {
    $bot = app(Assistant::class);
    $reply = $bot->ask($this->admin, 'wages 8 workers loading group Akram');
    expect($reply->pending['summary'])->toContain('8 worker(s), Loading / unloading')->toContain('Rs 12,000.00')->toContain('paid in cash');

    $done = $bot->confirm($this->admin, $reply->pending);
    expect($done->text)->toContain('Paid')->and($done->links[0]['label'])->toBe('Print daily wage slip')
        ->and(DailyWageSheet::query()->where('net_paise', 12_000_00)->sole()->status)->toBe(DailyWageSheet::PAID);
});
