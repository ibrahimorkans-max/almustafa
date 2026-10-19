<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\ManualVouchers;
use App\Domain\Accounts\PeriodLocks;
use App\Domain\Approvals\Actions\PeriodUnlock;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherLine;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('BKS');
    $this->accountant = Make::account($this->entity, 'ACCOUNTANT');
    $this->owner = Make::account($this->entity, 'SUPER_ADMIN');
    $this->owner2 = Make::account($this->entity, 'SUPER_ADMIN');
    app(AuthContextBinder::class)->bind($this->accountant);
    $this->books = app(Books::class);
    $this->chart = app(ChartOfAccounts::class);
    $this->capital = $this->chart->system('capital');
    $this->cash = $this->chart->system('cash');
});

function bindAs(LoginAccount $a): void
{
    app(AuthContextBinder::class)->bind($a);
}

it('installs the standard chart for every new entity', function () {
    expect(AccountGroup::query()->count())->toBe(count(ChartOfAccounts::GROUPS))
        ->and(LedgerAccount::query()->whereNotNull('system_key')->count())->toBe(count(ChartOfAccounts::SYSTEM_LEDGERS))
        ->and(AccountGroup::query()->where('code', 'SUNDRY_CREDITORS')->first()->parent->code)->toBe('CUR_LIAB');

    app(ChartOfAccounts::class)->install($this->entity->id); // idempotent
    expect(AccountGroup::query()->count())->toBe(count(ChartOfAccounts::GROUPS));
});

it('posts a balanced voucher with a gap-free number per type and financial year', function () {
    $v = $this->books->post('journal', now(), [
        ['account' => $this->cash, 'dr' => 100_000_00],
        ['account' => $this->capital, 'cr' => 100_000_00],
    ], 'Opening cash');
    $v2 = $this->books->post('journal', now(), [
        ['account' => $this->cash, 'dr' => 1], ['account' => $this->capital, 'cr' => 1],
    ]);

    expect($v->number)->toMatch('#^BKS/JV/\d{4}-\d{2}/0001$#')->and($v2->number)->toEndWith('/0002')
        ->and($v->total_paise)->toBe(100_000_00)
        ->and($this->books->balance($this->cash))->toBe(100_000_01)
        ->and($this->books->balance($this->capital))->toBe(-100_000_01)
        ->and($this->books->verify())->toBe([]);
});

it('refuses vouchers that do not balance or are malformed', function (array $lines, string $message) {
    $resolve = fn (array $l) => ['account' => $l['account'] === 'cash' ? $this->cash : $this->capital] + $l;
    expect(fn () => $this->books->post('journal', now(), array_map($resolve, $lines)))->toThrow(BusinessRuleViolation::class, $message);
    expect(Voucher::query()->count())->toBe(0);
})->with([
    'unbalanced' => [[['account' => 'cash', 'dr' => 100], ['account' => 'capital', 'cr' => 99]], 'do not match'],
    'one line' => [[['account' => 'cash', 'dr' => 100]], 'at least two'],
    'both sides' => [[['account' => 'cash', 'dr' => 100, 'cr' => 100], ['account' => 'capital', 'cr' => 0, 'dr' => 0]], 'two lines'],
    'negative' => [[['account' => 'cash', 'dr' => -100], ['account' => 'capital', 'cr' => -100]], 'positive'],
]);

it('keeps vouchers immutable and corrects by a single reversal', function () {
    $v = $this->books->post('journal', now(), [['account' => $this->cash, 'dr' => 500], ['account' => $this->capital, 'cr' => 500]]);

    $r = $this->books->reverse($v, 'Posted twice');
    expect($r->reverses_id)->toBe($v->id)->and($r->narration)->toContain('Posted twice')
        ->and($this->books->balance($this->cash))->toBe(0);
    expect(fn () => $this->books->reverse($v, 'again'))->toThrow(BusinessRuleViolation::class, 'already reversed');
    expect(fn () => $this->books->reverse($r, 'undo'))->toThrow(BusinessRuleViolation::class);

    // Last: on PostgreSQL a rejected statement aborts the surrounding test transaction.
    expect(fn () => DB::table('vouchers')->where('id', $v->id)->update(['total_paise' => 1]))->toThrow(QueryException::class);
});

it('detects a voucher whose lines no longer balance', function () {
    $v = $this->books->post('journal', now(), [['account' => $this->cash, 'dr' => 500], ['account' => $this->capital, 'cr' => 500]]);
    // Simulate tampering below the application (triggers off for the test on this connection is not possible; insert an extra line).
    VoucherLine::query()->create([
        'voucher_id' => $v->id, 'line_no' => 3, 'ledger_account_id' => $this->cash->id, 'dr_paise' => 100, 'cr_paise' => 0,
        'voucher_date' => $v->voucher_date->toDateString(), 'created_at' => now(),
    ]);

    expect($this->books->verify())->toHaveCount(1);
    Make::reset();
    expect(Artisan::call('books:verify'))->toBe(1);
});

it('keeps bank and cash out of journals', function () {
    expect(fn () => app(ManualVouchers::class)->journal(now(), [
        ['account' => $this->cash, 'dr' => 100], ['account' => $this->capital, 'cr' => 100],
    ], 'Cash adjustment'))->toThrow(BusinessRuleViolation::class, 'cannot be used in a journal');
});

it('locks a finished month and reopens it through an approval request', function () {
    $lastMonth = now('Asia/Karachi')->subMonthNoOverflow();
    bindAs($this->owner);
    app(PeriodLocks::class)->lock($lastMonth->format('Y-m'));

    expect(fn () => $this->books->post('journal', $lastMonth, [['account' => $this->cash, 'dr' => 1], ['account' => $this->capital, 'cr' => 1]]))
        ->toThrow(BusinessRuleViolation::class, 'locked');
    expect(fn () => app(PeriodLocks::class)->lock(now('Asia/Karachi')->format('Y-m')))->toThrow(BusinessRuleViolation::class, 'finished month');
    bindAs($this->accountant);
    expect(fn () => app(PeriodLocks::class)->lock($lastMonth->format('Y-m')))->toThrow(BusinessRuleViolation::class, 'period.lock');

    bindAs($this->owner);
    // The mill's Super Admin needs no second person: the request is applied at once.
    $request = app(ApprovalService::class)->submit(PeriodUnlock::KEY, ['period' => $lastMonth->format('Y-m'), 'reason' => 'Missed a freight bill'], 'Unlock');

    expect($request->fresh()->status)->toBe(ApprovalRequest::STATUS_EXECUTED)
        ->and(app(PeriodLocks::class)->isLocked($lastMonth))->toBeFalse();
    $this->books->post('journal', $lastMonth, [['account' => $this->cash, 'dr' => 1], ['account' => $this->capital, 'cr' => 1]]);
});

it('keeps each mill’s books to itself', function () {
    $this->books->post('journal', now(), [['account' => $this->cash, 'dr' => 1], ['account' => $this->capital, 'cr' => 1]]);
    bindAs(Make::account(Make::entity('OTH'), 'ACCOUNTANT'));

    expect(Voucher::query()->count())->toBe(0)->and(VoucherLine::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->where('id', $this->cash->id)->exists())->toBeFalse();
});
