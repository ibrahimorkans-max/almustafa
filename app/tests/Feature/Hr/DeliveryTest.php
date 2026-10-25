<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Documents\DocumentVerifier;
use App\Domain\Documents\Engine\DocumentBatches;
use App\Domain\Documents\Engine\DocumentService;
use App\Domain\Documents\Engine\DocumentSharing;
use App\Domain\Hr\Payroll\PayrollService;
use App\Domain\Hr\Payroll\PayrollSettings;
use App\Domain\Hr\Payroll\SalaryPaymentService;
use App\Domain\Hr\SalaryStructureService;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\MyDocumentsPage;
use App\Filament\Resources\Hr\DocumentBatchResource\Pages\ListDocumentBatches;
use App\Filament\Resources\Hr\GeneratedDocumentResource\Pages\ListGeneratedDocuments;
use App\Mail\DocumentMail;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\Documents\DocumentAccessLog;
use App\Models\Documents\DocumentBatch;
use App\Models\Documents\DocumentShare;
use App\Models\Documents\GeneratedDocument;
use App\Models\Entity;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\Shift;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('DLV'));
    $this->gen = Shift::query()->where('code', 'GEN')->value('id');
    $this->a = dlEmployee($this, 30000);
    $this->b = dlEmployee($this, 150000);
    $this->docs = app(DocumentService::class);
    $this->sharing = app(DocumentSharing::class);
});

function dlEmployee(object $t, int $basicRupees): Employee
{
    $t->f->as($t->f->hr);
    $e = $t->f->employee(['joining_date' => '2026-01-01']);
    $e->forceFill(['shift_id' => $t->gen])->save();
    app(SalaryStructureService::class)->create($e, CarbonImmutable::parse('2026-01-01'), 'monthly', ['BASIC' => $basicRupees * 100], 'Joining');

    return $e->fresh();
}

/** Punches for September 2026, payroll calculated, approved and finalized; back as HR. */
function dlFinalize(object $t): PayrollRun
{
    foreach ([$t->a, $t->b] as $e) {
        $start = CarbonImmutable::parse('2026-09-01', 'Asia/Karachi');
        for ($d = $start; $d->month === 9; $d = $d->addDay()) {
            if ($d->isSunday()) {
                continue;
            }
            foreach ([['in', '09:00'], ['out', '18:00']] as [$kind, $time]) {
                $at = CarbonImmutable::parse($d->toDateString().' '.$time, 'Asia/Karachi')->utc()->format('Y-m-d H:i:s.v');
                AttendanceLog::query()->create(['employee_id' => $e->id, 'kind' => $kind, 'source' => 'app', 'occurred_at' => $at, 'received_at' => $at,
                    'time_check' => 'verified', 'client_event_id' => (string) Str::uuid(), 'status' => 'valid', 'reasons' => [], 'created_at' => now()]);
            }
        }
    }
    $t->f->as($t->f->hr);
    $payroll = app(PayrollService::class);
    $run = $payroll->calculate($payroll->lock($payroll->open('2026-09')));
    $t->f->as($t->f->accountant);
    $run = $payroll->finalize($payroll->approve($run));
    $t->f->as($t->f->hr);

    return $run;
}

function dlPayslip(object $t, PayrollRun $run, Employee $e): GeneratedDocument
{
    return $t->docs->issue('payslip', $run->currentItems()->where('employee_id', $e->id)->sole()->id);
}

describe('sharing', function () {
    it('shows nothing of a company whose access is paused: shared links close and the verify page hides the details', function () {
        $doc = dlPayslip($this, dlFinalize($this), $this->a);
        $path = (string) parse_url($this->sharing->share($doc, 'link', null, 24)['url'], PHP_URL_PATH);
        $customerId = Make::system(fn () => Entity::query()->whereKey($doc->entity_id)->value('customer_id'));
        Make::system(fn () => Customer::query()->whereKey($customerId)->update(['status' => Customer::SUSPENDED]));
        app(CustomerAccess::class)->forget();

        $this->get($path)->assertStatus(410);
        $check = app(DocumentVerifier::class)->verify($doc->number, $doc->verify_code);
        expect($check['facts'])->toBe(['Status' => 'Issued by this system. The issuer\'s account is blocked, so its details are not shown.']);

        Make::system(fn () => Customer::query()->whereKey($customerId)->update(['status' => Customer::ACTIVE]));
        app(CustomerAccess::class)->forget();
        $this->get($path)->assertOk();
    });

    it('opens a link until it expires, is used up or is revoked, and logs every opening and refusal', function () {
        $doc = dlPayslip($this, dlFinalize($this), $this->a);
        $r = $this->sharing->share($doc, 'link', null, 24, 2);
        $path = parse_url($r['url'], PHP_URL_PATH);
        expect($r['url'])->toContain('/d/')->and(DocumentShare::query()->sole()->token_hash)->not->toContain(basename($path));

        $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->f->as($this->f->hr);
        $this->get($path)->assertOk();
        $this->f->as($this->f->hr);
        $this->get($path)->assertStatus(410)->assertSee('no longer open');           // 2 openings allowed
        $this->f->as($this->f->hr);
        $this->get('/d/'.Str::random(48))->assertStatus(410);
        $this->f->as($this->f->hr);
        expect(DocumentAccessLog::query()->where('action', 'open_link')->count())->toBe(2)
            ->and(DocumentAccessLog::query()->where('action', 'refused_link')->count())->toBe(1);

        $this->f->as($this->f->hr);
        $second = $this->sharing->share($doc, 'link', null, 24);
        $this->sharing->revoke($second['share']);
        $this->get(parse_url($second['url'], PHP_URL_PATH))->assertStatus(410);
        $this->f->as($this->f->hr);
        $third = $this->sharing->share($doc, 'link', null, 1);
        $this->travel(2)->hours();
        $this->get(parse_url($third['url'], PHP_URL_PATH))->assertStatus(410);
        $this->f->as($this->f->hr);
        expect(DocumentAccessLog::query()->where('action', 'refused_link')->get()->pluck('meta.why')->sort()->values()->all())->toBe(['expired', 'expired', 'revoked']);
    });

    it('protects a shared payslip with the CNIC, e-mails the original, and gives a WhatsApp link', function () {
        Mail::fake();
        $doc = dlPayslip($this, dlFinalize($this), $this->a);
        $protected = $this->sharing->share($doc, 'link', null, 24, null, true);
        $pdf = (string) $this->get(parse_url($protected['url'], PHP_URL_PATH))->assertOk()->getContent();
        $this->f->as($this->f->hr);
        expect($pdf)->toStartWith('%PDF-')->toContain('/Encrypt');

        $this->sharing->share($doc, 'email', 'iqbal@example.com');
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('iqbal@example.com') && count($m->attachments()) === 1 && $m->document->is($doc));
        expect(fn () => $this->sharing->share($doc, 'email', 'not-an-address'))->toThrow(BusinessRuleViolation::class, 'valid e-mail');

        $wa = $this->sharing->share($doc, 'whatsapp', '0300-1234567');
        expect($wa['send_url'])->toStartWith('https://wa.me/923001234567?text=')->and(urldecode($wa['send_url']))->toContain('/d/')
            ->and(fn () => $this->sharing->share($doc, 'sms', '12345'))->toThrow(BusinessRuleViolation::class, 'mobile number')
            ->and(DocumentShare::query()->where('channel', 'whatsapp')->sole()->recipient)->toBe('+923001234567')
            ->and(DocumentAccessLog::query()->where('action', 'share')->count())->toBe(3);
        expect($protected['message'])->toContain('last 5 digits');

        // SMS without a provider: the sender gets the text; the log gets neither the link nor the full number.
        $log = storage_path('logs/sms-test.log');
        @unlink($log);
        config(['logging.channels.sms_test' => ['driver' => 'single', 'path' => $log], 'ricemill.security.sms_log_channel' => 'sms_test']);
        $sms = $this->sharing->share($doc, 'sms', '0300-7654321');
        $logged = (string) file_get_contents($log);
        @unlink($log);
        expect($sms['message'])->toContain('/d/')
            ->and($logged)->toContain('SMS prepared')->toContain('+923*****321')->not->toContain('/d/')->not->toContain('7654321');
    });

    it('shares only the current revision, and only with the share permission', function () {
        $run = dlFinalize($this);
        $item = $run->currentItems()->where('employee_id', $this->a->id)->sole();
        $doc = $this->docs->issue('payslip', $item->id);
        $link = $this->sharing->share($doc, 'link', null, 24);

        $this->f->as($this->f->accountant);   // generates documents, may not share them
        expect(fn () => $this->sharing->share($doc, 'link'))->toThrow(BusinessRuleViolation::class, 'hr.documents.share');
        $this->f->as($this->f->owner);
        app(PayrollSettings::class)->set('cash_salary_limit_paise', 40_000_00, CarbonImmutable::parse('2026-01-01'), 'Test: higher limit');
        $this->f->as($this->f->accountant);
        app(SalaryPaymentService::class)->payCash($item, app(ChartOfAccounts::class)->system('cash'), CarbonImmutable::parse('2026-10-01'));
        $this->docs->revise($doc, 'Show the salary as paid');
        $this->f->as($this->f->hr);
        expect(fn () => $this->sharing->share($doc->fresh(), 'link'))->toThrow(BusinessRuleViolation::class, 'replaced');
        $this->get(parse_url($link['url'], PHP_URL_PATH))->assertStatus(410);
    });
});

describe('bulk and automation', function () {
    it('issues a batch on the queue with progress, lists failures, and gives one PDF and a ZIP', function () {
        $run = dlFinalize($this);
        $ids = [...$run->currentItems()->orderBy('employee->code')->pluck('id')->all(), (string) Str::uuid7()];
        $batch = app(DocumentBatches::class)->start('payslip', $ids, 'Payslips 2026-09', ['paper' => 'half_a4'])->fresh();
        expect($batch->status)->toBe('done')->and($batch->done_count)->toBe(3)->and($batch->percent())->toBe(100)
            ->and($batch->document_ids)->toHaveCount(2)->and($batch->failed)->toHaveCount(1)
            ->and(Storage::disk('local')->exists($batch->pdf_path))->toBeTrue();

        $zip = app(DocumentBatches::class)->download($batch, 'zip');
        $tmp = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($tmp, $zip['bytes']);
        $archive = new ZipArchive;
        $archive->open($tmp);
        $names = array_map(fn ($i) => $archive->getNameIndex($i), range(0, $archive->numFiles - 1));
        $archive->close();
        @unlink($tmp);
        expect($names)->toHaveCount(2)->and($names[0])->toStartWith($this->a->code.'-')->toEndWith('.pdf');

        $retry = app(DocumentBatches::class)->retryFailed($batch)->fresh();
        expect($retry->total)->toBe(1)->and($retry->failed)->toHaveCount(1)->and($retry->label)->toContain('(retry)');
        // Running again does not issue twice: payslips are issued once.
        expect(GeneratedDocument::query()->where('doc_type', 'payslip')->count())->toBe(2);
    });

    it('issues every payslip on finalization when the setting is on, and reminds about cash paid without a thumb', function () {
        $this->f->as($this->f->owner);
        app(PayrollSettings::class)->set('auto_issue_payslips', true, CarbonImmutable::parse('2026-07-01'), 'HR decision');
        app(PayrollSettings::class)->set('cash_salary_limit_paise', 40_000_00, CarbonImmutable::parse('2026-01-01'), 'Test: higher limit');
        $run = dlFinalize($this);
        expect(GeneratedDocument::query()->where('doc_type', 'payslip')->count())->toBe(2)
            ->and(DocumentBatch::query()->sole()->label)->toBe('Payslips 2026-09');

        $this->f->as($this->f->accountant);
        $payment = app(SalaryPaymentService::class)->payCash($run->currentItems()->where('employee_id', $this->a->id)->sole(), app(ChartOfAccounts::class)->system('cash'),
            CarbonImmutable::now('Asia/Karachi')->subDays(3));
        $this->artisan('hr:salary-ack-reminders')->assertSuccessful();
        $this->artisan('hr:salary-ack-reminders')->assertSuccessful();
        $this->f->as($this->f->hr);
        expect(Alert::query()->where('type', 'hr_salary_ack_missing')->where('subject_id', $payment->id)->count())->toBe(1);
    });
});

describe('self-service', function () {
    it('gives an employee their own documents only, on the phone and in the panel', function () {
        $run = dlFinalize($this);
        $mine = dlPayslip($this, $run, $this->a);
        $theirs = dlPayslip($this, $run, $this->b);
        $self = Make::account($this->f->entity, 'EMPLOYEE', ['person' => $this->a->person]);

        $h = Make::apiHeaders(Make::apiTokens($self, 'phone-docs-00000001'), 'phone-docs-00000001');
        $list = $this->withHeaders($h)->getJson('/api/v1/documents/me')->assertOk()->json('documents');
        expect($list)->toHaveCount(1)->and($list[0]['number'])->toBe($mine->number)->and($list[0]['period'])->toBe('2026-09');
        $this->withHeaders($h)->get("/api/v1/documents/{$mine->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->withHeaders($h)->get("/api/v1/documents/{$theirs->id}/pdf")->assertForbidden();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($self, 'web')->withSession(Make::webSession($self));
        $this->f->as($self);
        $this->get('/admin/my-documents')->assertOk();
        $this->f->as($self);
        Livewire::test(MyDocumentsPage::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs])
            ->callTableAction('download', $mine->id)->assertFileDownloaded();
        $this->get('/admin/hr/documents')->assertForbidden();
    });

    it('shares from the documents list and starts a bulk generation on screen', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $run = dlFinalize($this);
        $doc = dlPayslip($this, $run, $this->a);
        $this->actingAs($this->f->hr, 'web')->withSession(Make::webSession($this->f->hr));
        $this->f->as($this->f->hr);
        Livewire::test(ListGeneratedDocuments::class)
            ->callTableAction('share', $doc->id, ['channel' => 'whatsapp', 'recipient' => '03001234567', 'hours' => 72, 'protect' => true])
            ->assertNotified('Link ready.');
        expect(DocumentShare::query()->sole()->protected)->toBeTrue();
        Livewire::test(ListDocumentBatches::class)
            ->callAction('start', ['doc_type' => 'payslip', 'run' => $run->id, 'language' => 'bilingual', 'paper' => 'half_a4'])
            ->assertNotified('Started: 2 documents. The list shows the progress.');
        expect(DocumentBatch::query()->sole()->status)->toBe('done');
    });
});
