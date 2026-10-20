<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Reports\CashBankBooks;
use App\Models\Accounts\LedgerAccount;
use Carbon\CarbonImmutable;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('CBB');
    $this->accountant = Make::account($this->entity, 'ACCOUNTANT');
    app(AuthContextBinder::class)->bind($this->accountant);
    $chart = app(ChartOfAccounts::class);
    $this->capital = $chart->system('capital');
    $this->cash = $chart->system('cash');
    $this->bank = LedgerAccount::query()->create([
        'entity_id' => $this->entity->id, 'group_id' => $chart->group('BANK_ACCOUNTS')->id,
        'code' => 'BANK-HBL', 'name' => 'HBL current account', 'kind' => 'bank',
    ]);
    $this->today = CarbonImmutable::now('Asia/Karachi')->startOfDay();
    $books = app(Books::class);
    // Before the period: Rs 1,000 into cash (the opening balance).
    $books->post('receipt', $this->today->subDays(5), [['account' => $this->cash, 'dr' => 100_000], ['account' => $this->capital, 'cr' => 100_000]], 'Opening capital');
    // In the period: Rs 500 more cash yesterday; today Rs 300 from cash to the bank.
    $books->post('receipt', $this->today->subDay(), [['account' => $this->cash, 'dr' => 50_000], ['account' => $this->capital, 'cr' => 50_000]], 'Capital introduced');
    $books->post('contra', $this->today, [['account' => $this->bank, 'dr' => 30_000], ['account' => $this->cash, 'cr' => 30_000]], 'Cash deposited');
});

it('gives the cash book a daily closing and each entry with the other side', function () {
    [$daily, $entries] = app(CashBankBooks::class)->build('cash', $this->today->subDays(2), $this->today);

    expect($daily['columns'])->toBe(['Day', 'Opening', 'Received', 'Paid', 'Closing'])
        ->and(array_slice($daily['rows'][0], 1))->toBe(['1,000.00', '500.00', '0.00', '1,500.00'])
        ->and(array_slice($daily['rows'][1], 1))->toBe(['1,500.00', '0.00', '300.00', '1,200.00'])
        ->and(end($daily['rows']))->toBe(['TOTAL', '1,000.00', '500.00', '300.00', '1,200.00']);

    expect($entries['rows'][0])->toBe(['', '', 'Opening balance', '', '', '', '1,000.00'])
        ->and($entries['rows'][1][2])->toBe($this->capital->name)
        ->and($entries['rows'][2][2])->toBe('HBL current account')
        ->and($entries['rows'][2][5])->toBe('300.00')
        ->and(end($entries['rows'])[6])->toBe('1,200.00');
});

it('shows the transfer in the bank book as money received', function () {
    $parts = app(CashBankBooks::class)->build('bank', $this->today->subDays(2), $this->today);

    expect($parts)->toHaveCount(2)
        ->and($parts[0]['title'])->toContain('HBL current account')
        ->and(end($parts[0]['rows']))->toBe(['TOTAL', '0.00', '300.00', '0.00', '300.00'])
        ->and($parts[1]['rows'][1][2])->toBe($this->cash->name);
});

it('says so when there are no entries in the period', function () {
    $parts = app(CashBankBooks::class)->build('bank', $this->today->subDays(30), $this->today->subDays(10));

    expect($parts[0]['lines'])->toBe(['No entries in this period.']);
});
