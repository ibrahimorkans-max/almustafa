<?php

declare(strict_types=1);

use App\Domain\Access\EntityProvisioner;
use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\Roznamcha;
use App\Domain\Accounts\RoznamchaOcr;
use App\Domain\Banking\BankEmailImport;
use App\Domain\Banking\BankEmailParser;
use App\Domain\Banking\Mailbox;
use App\Domain\Banking\MailMessage;
use App\Domain\Banking\MailOAuth;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\Platform\EmailIntegrationPage;
use App\Filament\Resources\Accounts\RoznamchaResource\Pages\CreateRoznamchaPage;
use App\Filament\Resources\Banking\BankEmailTransactionResource\Pages\ListBankEmailTransactions;
use App\Filament\Resources\Banking\EmailConnectionResource\Pages\ListEmailConnections;
use App\Models\Accounts\BankStatementLine;
use App\Models\Accounts\RoznamchaPage;
use App\Models\Accounts\Voucher;
use App\Models\Banking\BankEmailTemplate;
use App\Models\Banking\BankEmailTransaction;
use App\Models\Banking\EmailConnection;
use App\Models\Banking\EmailSyncRun;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

/*
 * Phase 3 of the October 2026 list: bank notification e-mails (mailboxes, templates, reading,
 * duplicates, party matching, review, bank statement) and the digital roznamcha with OCR.
 */

/** A mailbox that hands back the e-mails a test puts in it. */
final class FakeMailbox implements Mailbox
{
    /** @var list<MailMessage> */
    public array $messages = [];

    public function fetch(EmailConnection $connection, CarbonImmutable $since, int $limit = 100): array
    {
        return $this->messages;
    }
}

function alert(string $id, string $text, string $from = 'alerts@hbl.com'): MailMessage
{
    return new MailMessage($id, "HBL Alerts <{$from}>", 'HBL Transaction Alert', CarbonImmutable::parse('2026-10-28 10:15', 'Asia/Karachi'), $text);
}

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('PH3'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->mailbox = new FakeMailbox;
    app()->instance(Mailbox::class, $this->mailbox);

    Make::system(fn () => BankEmailTemplate::query()->create([
        'bank_name' => 'HBL', 'sender_pattern' => 'alerts@hbl\.com', 'subject_pattern' => 'Transaction Alert',
        'amount_pattern' => 'PKR\s*(?<amount>[\d,]+(?:\.\d{1,2})?)', 'credit_pattern' => 'credited', 'debit_pattern' => 'debited',
        'reference_pattern' => 'Ref(?:erence)?\s*#?\s*:?\s*([A-Z0-9]+)', 'account_pattern' => 'A/C\s*(\*+\d{4})',
        'counterparty_pattern' => '(?:from|to)\s+(.+?)\s+A/C', 'counterparty_account_pattern' => '(?:from|to)\s+.+?\s+A/C\s+(PK\d{2}[A-Z]{4}\d{16}|\*+\d{4})\s',
        'date_pattern' => 'on\s+(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2})', 'date_format' => 'd-M-Y H:i',
    ]));
    $this->f->as($this->f->owner);
    $this->conn = new EmailConnection(['provider' => 'imap', 'email' => 'accounts@mill.pk', 'ledger_account_id' => $this->f->bank->id, 'sync_minutes' => 30]);
    $this->conn->credentials = ['host' => 'imap.mill.pk', 'port' => 993, 'username' => 'accounts', 'password' => 'app-secret-123'];
    $this->conn->status = 'connected';
    $this->conn->save();
    $this->iban = PakistanIds::ibanFor('HABB', '0012345678901234');
});

function ph3SignIn(object $t, LoginAccount $account): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

it('reads a bank alert with its template: amount, side, reference, accounts and time', function () {
    $r = app(BankEmailParser::class)->parse(
        alert('m1', "Your A/C ****4321 has been credited with PKR 1,25,000.50 from Muhammad Aslam A/C {$this->iban} on 28-Oct-2026 10:12. Ref: FT12345"),
        BankEmailTemplate::query()->get(),
    );

    expect($r['error'])->toBeNull()->and($r['fields'])->toMatchArray([
        'amount_paise' => 12500050, 'direction' => 'credit', 'reference' => 'FT12345', 'account_hint' => '****4321',
        'counterparty' => 'Muhammad Aslam', 'counterparty_account' => $this->iban, 'txn_date' => '2026-10-28', 'txn_time' => '10:12:00',
    ]);
});

it('imports each e-mail once, flags duplicates, skips other senders and matches parties only on verified facts', function () {
    $this->mailbox->messages = [
        alert('m1', "A/C ****4321 credited with PKR 50,000 from Muhammad Aslam A/C {$this->iban} on 28-Oct-2026 10:12. Ref: FT1"),
        alert('m2', 'A/C ****4321 credited with PKR 50,000 from Muhammad Aslam A/C ****1234 on 28-Oct-2026 10:12. Ref: FT1'), // same transaction again
        alert('m3', 'A/C ****4321 credited with PKR 7,000 from Muhammad Aslam A/C ****1234 on 28-Oct-2026 11:00. Ref: FT2'),
        alert('m4', 'A/C ****4321 debited with PKR 2,500 to Unknown Shop A/C ****9999 on 28-Oct-2026 12:00. Ref: FT3'),
        alert('m5', 'Your statement is ready.'),
        new MailMessage('m6', 'deals@shop.pk', 'Big sale', null, 'Buy now PKR 999 credited'),
    ];
    $run = app(BankEmailImport::class)->sync($this->conn);
    expect([$run->fetched, $run->imported, $run->duplicates, $run->failed, $run->error])->toBe([6, 3, 1, 1, null]);

    $tx = fn (string $id) => BankEmailTransaction::query()->where('message_id', $id)->sole();
    $seller = $this->f->inv->gate->seller;
    expect($tx('m1')->party_id)->toBe($seller->id)->and($tx('m1')->match_status)->toBe('matched')->and($tx('m1')->amount_paise)->toBe(5_000_000)
        ->and($tx('m1')->number)->toContain('/BNK/')
        ->and($tx('m2')->import_status)->toBe('duplicate')
        ->and($tx('m3')->match_status)->toBe('suggested')->and($tx('m3')->party_id)->toBe($seller->id) // last digits only: a person must confirm
        ->and($tx('m4')->match_status)->toBe('unmatched')->and($tx('m4')->direction)->toBe('debit')
        ->and($tx('m5')->import_status)->toBe('failed')
        ->and(BankEmailTransaction::query()->where('message_id', 'm6')->exists())->toBeFalse();

    // Reading again keeps nothing twice.
    $again = app(BankEmailImport::class)->sync($this->conn);
    expect($again->imported)->toBe(0)->and(BankEmailTransaction::query()->count())->toBe(5);
});

it('posts nothing until a person confirms, then puts it on the bank statement once', function () {
    $this->mailbox->messages = [alert('m1', "A/C ****4321 credited with PKR 50,000 from Muhammad Aslam A/C {$this->iban} on 28-Oct-2026 10:12. Ref: FT1")];
    app(BankEmailImport::class)->sync($this->conn);
    $tx = BankEmailTransaction::query()->sole();
    expect(BankStatementLine::query()->count())->toBe(0)->and(Voucher::query()->where('type', 'receipt')->count())->toBe(0);

    $import = app(BankEmailImport::class);
    expect(fn () => $import->toStatement($tx))->toThrow(BusinessRuleViolation::class, 'Confirm');
    $import->confirm($tx, null);
    $tx = $import->toStatement($tx->fresh());
    expect($tx->reconciliation_status)->toBe('on_statement')
        ->and(BankStatementLine::query()->sole()->credit_paise)->toBe(5_000_000);
    expect(fn () => $import->toStatement($tx))->toThrow(BusinessRuleViolation::class);
});

it('records a failed reading, and refuses when the product owner switched bank e-mail off', function () {
    Make::system(fn () => Customer::query()->whereKey($this->f->entity->customer_id)->update(['bank_email_enabled' => false]));
    $run = app(BankEmailImport::class)->sync($this->conn);
    expect($run->error)->toContain('switched off')->and($this->conn->fresh()->status)->toBe('error')->and(EmailSyncRun::query()->count())->toBe(1);
});

it('connects Gmail with OAuth and keeps the tokens encrypted', function () {
    config(['ricemill.bank_email.google' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.secret-token', 'refresh_token' => 'refresh-xyz', 'expires_in' => 3600])]);
    ph3SignIn($this, $this->f->owner);
    $gmail = EmailConnection::query()->create(['provider' => 'google', 'email' => 'owner@gmail.com', 'ledger_account_id' => $this->f->bank->id]);

    $url = app(MailOAuth::class)->authorizeUrl($gmail);
    expect($url)->toContain('accounts.google.com')->toContain('gmail.readonly')->toContain('code_challenge=');
    $this->get(route('filament.admin.bank-email.callback', ['state' => str_repeat('x', 40), 'code' => 'abc']))->assertNotFound();
    $this->get(route('filament.admin.bank-email.callback', ['state' => $gmail->fresh()->oauth_state, 'code' => 'abc']))->assertRedirect();

    $gmail->refresh();
    expect($gmail->status)->toBe('connected')->and($gmail->credentials['access_token'])->toBe('ya29.secret-token');
    $raw = (string) DB::table('email_connections')->where('id', $gmail->id)->value('credentials');
    expect($raw)->not->toContain('ya29')->not->toContain('refresh-xyz');
});

it('keeps one mill\'s bank e-mail transactions from another mill', function () {
    $this->mailbox->messages = [alert('m1', 'A/C ****4321 credited with PKR 1,000 from X A/C ****1111 on 28-Oct-2026 10:12. Ref: FT9')];
    app(BankEmailImport::class)->sync($this->conn);
    $otherMill = Make::system(fn () => app(EntityProvisioner::class)->create([
        'code' => 'PH3B', 'name' => 'Mill PH3B', 'status' => Entity::STATUS_ACTIVE, 'customer_id' => Make::customer('OTHER3')->id,
    ]));
    $other = new AccountsFixtures($otherMill);
    $other->as($other->owner);
    expect(BankEmailTransaction::query()->count())->toBe(0)->and(EmailConnection::query()->count())->toBe(0);
});

it('opens the bank e-mail screens for the mill and the integration page for the product owner', function () {
    ph3SignIn($this, $this->f->owner);
    Livewire::test(ListEmailConnections::class)->assertOk()->assertSee('accounts@mill.pk');
    Livewire::test(ListBankEmailTransactions::class)->assertOk();

    $admin = Make::platformAdmin($this->f->entity);
    ph3SignIn($this, $admin);
    Livewire::test(EmailIntegrationPage::class)->assertOk()->assertSee('Gmail / Google Workspace');
});

it('suggests roznamcha lines from OCR text', function () {
    $lines = RoznamchaOcr::linesFromText("Jama Muhammad Aslam 25,000\nBanam Diesel pump 3,500/-\nTotal\n");
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toMatchArray(['account' => 'Muhammad Aslam', 'credit' => '25000', 'debit' => ''])
        ->and($lines[1])->toMatchArray(['account' => 'Diesel pump', 'debit' => '3500', 'credit' => '']);
});

it('records a roznamcha page, has it checked by a second person and posts it once', function () {
    $this->f->as($this->f->accountant);
    $svc = app(Roznamcha::class);
    $cash = app(ChartOfAccounts::class)->system('cash');
    $before = app(Books::class)->balance($cash);
    $page = $svc->record(CarbonImmutable::now('Asia/Karachi'), 'manual', note: 'Typed from the book');
    $fuel = app(ChartOfAccounts::class)->system('brokerage');
    $svc->saveLines($page, [
        ['party_id' => $this->f->inv->gate->seller->id, 'credit' => '25,000', 'narration' => 'Advance back'],
        ['ledger_account_id' => $fuel->id, 'debit' => '3500'],
    ]);

    expect(fn () => $svc->verify($page))->toThrow(BusinessRuleViolation::class, 'second person');
    $this->f->as($this->f->owner);
    expect(fn () => $svc->post($page))->toThrow(BusinessRuleViolation::class, 'Check the page');
    $svc->verify($page);
    $page = $svc->post($page);

    expect($page->status)->toBe('posted')->and($page->lines()->whereNotNull('voucher_id')->count())->toBe(2)
        ->and(app(Books::class)->balance($cash) - $before)->toBe(2_150_000);
    expect(fn () => $svc->post($page))->toThrow(BusinessRuleViolation::class, 'already posted');
    expect(fn () => $svc->saveLines($page, []))->toThrow(BusinessRuleViolation::class);
});

it('adds a scanned roznamcha page on screen and keeps the original', function () {
    config(['ricemill.ocr.engine' => 'none']);
    ph3SignIn($this, $this->f->owner);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $file = UploadedFile::fake()->createWithContent('page.png', $png);
    Livewire::test(CreateRoznamchaPage::class)
        ->fillForm(['entry_date' => now('Asia/Karachi')->toDateString(), 'source' => 'camera', 'scan' => $file,
            'cash_ledger_id' => app(ChartOfAccounts::class)->system('cash')->id])
        ->call('create')->assertHasNoFormErrors();
    $page = RoznamchaPage::query()->sole();
    expect($page->stored_file_id)->not->toBeNull()->and($page->number)->toContain('/RZN/');
    $this->get(route('filament.admin.roznamcha.scan', ['id' => $page->id]))->assertOk()->assertHeader('Content-Type', 'image/png');
});
