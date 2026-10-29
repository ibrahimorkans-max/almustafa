<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Documents\DocumentVerifier;
use App\Domain\Documents\Engine\DocumentRenderer;
use App\Domain\Documents\Engine\DocumentService;
use App\Domain\Documents\Engine\TemplateRepository;
use App\Domain\Hr\Payroll\PayrollService;
use App\Domain\Hr\Payroll\PayrollSettings;
use App\Domain\Hr\Payroll\SalaryPaymentService;
use App\Domain\Hr\SalaryStructureService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\DocumentTemplateResource\Pages\ManageDocumentTemplates;
use App\Filament\Resources\Hr\GeneratedDocumentResource\Pages\ListGeneratedDocuments;
use App\Filament\Resources\Hr\PayrollRunResource\Pages\ViewPayrollRun;
use App\Filament\Resources\Hr\PayrollRunResource\RelationManagers\ItemsRelationManager;
use App\Models\Documents\DocumentAccessLog;
use App\Models\Documents\DocumentTemplate;
use App\Models\Documents\GeneratedDocument;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunAttendance;
use App\Models\Hr\Shift;
use App\Support\Values\Money;
use App\Support\Values\PakistanIds;
use App\Support\Values\UrduAmountInWords;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('DOC'));
    $this->gen = Shift::query()->where('code', 'GEN')->value('id');
    $this->a = docEmployee($this, 30000);
    $this->b = docEmployee($this, 150000);
    $this->svc = app(DocumentService::class);
    $this->run = function (bool $finalize = true): PayrollRun {
        docPunchMonth($this->a, '2026-09');
        docPunchMonth($this->b, '2026-09');
        $this->f->as($this->f->hr);
        $payroll = app(PayrollService::class);
        $run = $payroll->calculate($payroll->lock($payroll->open('2026-09')));
        if ($finalize) {
            $this->f->as($this->f->accountant);
            $run = $payroll->finalize($payroll->approve($run));
        }
        $this->f->as($this->f->hr);

        return $run;
    };
});

function docEmployee(object $t, int $basicRupees): Employee
{
    $t->f->as($t->f->hr);
    $e = $t->f->employee(['joining_date' => '2026-01-01']);
    $e->forceFill(['shift_id' => $t->gen])->save();
    app(SalaryStructureService::class)->create($e, CarbonImmutable::parse('2026-01-01'), 'monthly', ['BASIC' => $basicRupees * 100], 'Joining');

    return $e->fresh();
}

function docPunchMonth(Employee $e, string $month): void
{
    $start = CarbonImmutable::parse($month.'-01', 'Asia/Karachi');
    for ($d = $start; $d->month === $start->month; $d = $d->addDay()) {
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

function docItem(PayrollRun $run, Employee $e): PayrollItem
{
    return $run->currentItems()->where('employee_id', $e->id)->sole();
}

describe('amounts in Urdu', function () {
    it('writes amounts in Urdu words in lakh and crore, and Urdu digits', function () {
        expect(UrduAmountInWords::rupees(125_050_50))->toBe('ایک لاکھ پچیس ہزار پچاس روپے اور پچاس پیسے صرف')
            ->and(UrduAmountInWords::rupees(2_37_45_000_00))->toBe('دو کروڑ سینتیس لاکھ پینتالیس ہزار روپے صرف')
            ->and(UrduAmountInWords::number(99))->toBe('ننانوے')
            ->and(UrduAmountInWords::digits('Rs 1,234.50'))->toBe('Rs ۱,۲۳۴.۵۰');
    });
});

describe('issuing', function () {
    it('previews a calculated payroll as a DRAFT only, and issues the payslip once it is finalized', function () {
        $run = ($this->run)(false);
        $item = docItem($run, $this->a);
        $draft = $this->svc->preview('payslip', $item->id);
        expect(substr($draft['bytes'], 0, 5))->toBe('%PDF-')->and(DocumentRenderer::$lastHtml)->toContain(Money::paise($item->net_paise)->grouped())
            ->and(fn () => $this->svc->issue('payslip', $item->id))->toThrow(BusinessRuleViolation::class, 'not final yet')
            ->and(GeneratedDocument::query()->count())->toBe(0);

        $this->f->as($this->f->accountant);
        $payroll = app(PayrollService::class);
        $run = $payroll->finalize($payroll->approve($run));
        $item = docItem($run, $this->a);
        $doc = $this->svc->issue('payslip', $item->id);
        expect($doc->number)->toBe($item->payslip->number)->and($doc->employee_id)->toBe($this->a->id)
            ->and($this->svc->issue('payslip', $item->id)->id)->toBe($doc->id)          // issued once
            ->and(GeneratedDocument::query()->count())->toBe(1)
            ->and(DocumentAccessLog::query()->where('action', 'issue')->count())->toBe(1);
    });

    it('prints the same figures in every layout and language', function () {
        $run = ($this->run)();
        $item = docItem($run, $this->b);
        $doc = $this->svc->issue('payslip', $item->id);
        $net = Money::paise($item->net_paise)->grouped();
        $tax = Money::paise($item->line('deductions', 'TAX'))->grouped();
        foreach (array_keys(DocumentTemplate::LAYOUTS) as $layout) {
            foreach (['en', 'ur', 'bilingual'] as $language) {
                $this->svc->reprint($doc, ['layout' => $layout, 'language' => $language]);
                expect(DocumentRenderer::$lastHtml)->toContain($net)->toContain($tax);
            }
        }
        expect(DocumentRenderer::$lastHtml)->toContain(UrduAmountInWords::rupees($item->net_paise))->toContain('خالص تنخواہ')
            ->and($doc->fresh()->reprintCount())->toBe(12);

        $this->svc->reprint($doc, ['language' => 'ur', 'digits' => 'urdu']);
        expect(DocumentRenderer::$lastHtml)->toContain(UrduAmountInWords::digits($net));
    });

    it('keeps the original byte for byte, marks reprints DUPLICATE and counts them', function () {
        $run = ($this->run)();
        $doc = $this->svc->issue('payslip', docItem($run, $this->a)->id);
        $original = $this->svc->original($doc);
        expect(substr($original['bytes'], 0, 5))->toBe('%PDF-');

        $this->svc->reprint($doc);
        $this->svc->reprint($doc, ['paper' => 'a5']);
        expect(DocumentRenderer::$lastHtml)->not->toBeNull()
            ->and($doc->reprintCount())->toBe(2)
            ->and(DocumentAccessLog::query()->where('generated_document_id', $doc->id)->pluck('action')->sort()->values()->all())
            ->toBe(['download', 'issue', 'reprint', 'reprint']);
        $meta = DocumentAccessLog::query()->where('action', 'reprint')->get()->first(fn ($l) => $l->meta['paper'] === 'a5')->meta;
        expect($meta['copy'])->toBe(2)->and($meta['paper'])->toBe('a5');
    });

    it('masks the CNIC and bank account unless the user may see them, and then logs it', function () {
        $this->a->forceFill(['bank_account_number' => PakistanIds::ibanFor('HABB', '0000001234567890')])->save();
        $run = ($this->run)();
        $doc = $this->svc->issue('payslip', docItem($run, $this->a)->id);
        $full = PakistanIds::formatCnic(Employee::query()->find($this->a->id)->cnic);
        expect(json_encode($doc->payload, JSON_UNESCAPED_UNICODE))->not->toContain($full)->not->toContain('1234567890');

        $this->svc->reprint($doc, ['language' => 'en']);
        expect(DocumentRenderer::$lastHtml)->not->toContain($full)->toContain($this->a->maskedCnic());

        $this->f->as($this->f->accountant);   // generates documents, but may not see full identifiers
        expect(fn () => $this->svc->reprint($doc, ['unmask' => true]))->toThrow(BusinessRuleViolation::class, 'hr.employee.sensitive.view');
        $this->f->as($this->f->hr);
        $this->svc->reprint($doc, ['unmask' => true, 'language' => 'en']);
        expect(DocumentRenderer::$lastHtml)->toContain($full)->toContain(PakistanIds::ibanFor('HABB', '0000001234567890'))
            ->and(DocumentAccessLog::query()->where('action', 'reprint')->get()->filter(fn ($l) => $l->meta['unmasked'])->count())->toBe(1);
    });

    it('verifies a genuine document by number and code only, and shows a corrected one as replaced', function () {
        $run = ($this->run)();
        $item = docItem($run, $this->a);
        $doc = $this->svc->issue('payslip', $item->id);
        $verifier = app(DocumentVerifier::class);
        $check = $verifier->verify($doc->number, $doc->verify_code);
        expect($check['facts']['Status'])->toBe('Valid')->and($check['facts'])->not->toHaveKey('Net pay')
            ->and($verifier->verify($doc->number, 'AAAA-BBBB-CCCC'))->toBeNull()
            ->and(fn () => $this->svc->revise($doc, 'Nothing really'))->toThrow(BusinessRuleViolation::class, 'nothing to correct');

        // Paying the salary changes what the payslip shows (paid): a correction is issued as R1.
        $this->f->as($this->f->owner);
        app(PayrollSettings::class)->set('cash_salary_limit_paise', 40_000_00, CarbonImmutable::parse('2026-01-01'), 'Test: higher limit');
        $this->f->as($this->f->accountant);
        app(SalaryPaymentService::class)->payCash($item, app(ChartOfAccounts::class)->system('cash'), CarbonImmutable::parse('2026-10-01'));
        $r1 = $this->svc->revise($doc, 'Show the salary as paid');
        expect($r1->revision)->toBe(1)->and($r1->supersedes_id)->toBe($doc->id)->and($r1->displayNumber())->toBe($doc->number.' R1')
            ->and($doc->fresh()->isCurrent())->toBeFalse()
            ->and($this->svc->issue('payslip', $item->id)->id)->toBe($r1->id)
            ->and($verifier->verify($doc->number, $doc->verify_code)['facts']['Status'])->toStartWith('Replaced by '.$doc->number.' R1')
            ->and($verifier->verify($r1->displayNumber(), $r1->verify_code)['facts']['Status'])->toBe('Valid')
            ->and(fn () => $this->svc->revise($doc, 'Correct the old one'))->toThrow(BusinessRuleViolation::class, 'already replaced');
    });

    it('numbers certificates gap-free per series and tax year, and keeps documents append-only', function () {
        $first = $this->svc->issue('salary_certificate', $this->a->id, ['type' => ['purpose' => 'bank account opening']]);
        $second = $this->svc->issue('employment_letter', $this->b->id);
        expect($first->number)->toEndWith('/CERT/2026-27/0001')->and($second->number)->toEndWith('/CERT/2026-27/0002');
        $this->svc->reprint($first, ['language' => 'en']);
        expect(DocumentRenderer::$lastHtml)->toContain('Rs 30,000.00')->toContain('bank account opening');
        $this->svc->reprint($second, ['language' => 'en']);
        expect(DocumentRenderer::$lastHtml)->not->toContain('Rs 1,50,000.00');

        expect(fn () => DB::table('generated_documents')->where('id', $first->id)->update(['payload' => '{}']))->toThrow(Exception::class);
    });
});

describe('formats and templates', function () {
    it('renders thermal, half-A4, HTML, receipt-printer text and password-protected PDFs', function () {
        $run = ($this->run)();
        $item = docItem($run, $this->a);
        $doc = $this->svc->issue('payslip', $item->id);
        $net = Money::paise($item->net_paise)->grouped();

        $thermal = $this->svc->reprint($doc, ['paper' => 'thermal80', 'language' => 'en']);
        expect($thermal['mime'])->toBe('application/pdf')->and(DocumentRenderer::$lastHtml)->toContain($net);
        $text = $this->svc->reprint($doc, ['paper' => 'thermal58', 'format' => 'escpos'])['bytes'];
        expect($text)->toStartWith("\x1B\x40")->toContain('Rs '.$net)->toContain('*** DUPLICATE #2 ***')->toContain($doc->verify_code);
        foreach (explode("\n", substr($text, 2, -4)) as $line) {
            expect(mb_strlen(rtrim($line)))->toBeLessThanOrEqual(32);
        }
        $html = $this->svc->reprint($doc, ['format' => 'html'])['bytes'];
        expect($html)->toContain('<svg')->toContain('DUPLICATE')->toContain($net);
        // Half A4: the original keeps one slip per page and prints in the top half of an A4 sheet.
        expect($this->svc->original($doc)['bytes'])->toContain('/MediaBox [0 0 595.280 841.890]');
        $protected = $this->svc->reprint($doc, ['password' => 'cnic5'])['bytes'];
        expect($protected)->toContain('/Encrypt')
            ->and(fn () => $this->svc->reprint($doc, ['password' => 'pin', 'pin' => '12']))->toThrow(BusinessRuleViolation::class, '4 to 12 digits');
        try {
            expect(substr($this->svc->reprint($doc, ['format' => 'png'])['bytes'], 1, 3))->toBe('PNG');
        } catch (BusinessRuleViolation $e) {
            expect($e->getMessage())->toContain('pdftoppm');
        }
    });

    it('versions templates: new documents use the new look, issued ones keep theirs; only template managers change them', function () {
        $repo = app(TemplateRepository::class);
        $repo->installStandard('payslip');
        expect(DocumentTemplate::query()->where('doc_type', 'payslip')->count())->toBe(4)
            ->and(DocumentTemplate::query()->where('doc_type', 'payslip')->where('is_default', true)->value('layout'))->toBe('classic');
        $run = ($this->run)();
        $before = $this->svc->issue('payslip', docItem($run, $this->a)->id);

        $classic = DocumentTemplate::query()->where('doc_type', 'payslip')->where('layout', 'classic')->sole();
        $this->f->as($this->f->accountant);
        expect(fn () => $repo->newVersion($classic, ['header_text' => 'x'], 'Try'))->toThrow(BusinessRuleViolation::class, 'template.manage');
        $this->f->as($this->f->hr);
        expect(fn () => $repo->newVersion($classic, ['accent' => 'green'], 'Colour'))->toThrow(BusinessRuleViolation::class, '#1F5F3A');
        $v2 = $repo->newVersion($classic, ['header_text' => 'Wages paid under the Payment of Wages Act, 1936', 'toggles' => ['employer' => true]], 'Act reference');
        $after = $this->svc->issue('payslip', docItem($run, $this->b)->id);
        expect($v2->version_no)->toBe(2)->and($after->template_version_id)->toBe($v2->id)->and($before->template_version_id)->not->toBe($v2->id);
        $this->svc->reprint($after, ['language' => 'en']);
        expect(DocumentRenderer::$lastHtml)->toContain('Payment of Wages Act, 1936')->toContain('Employer contributions');
        $this->svc->reprint($before, ['language' => 'en']);
        expect(DocumentRenderer::$lastHtml)->not->toContain('Payment of Wages Act, 1936');

        $compact = DocumentTemplate::query()->where('doc_type', 'payslip')->where('layout', 'compact')->sole();
        $repo->makeDefault($compact);
        expect(DocumentTemplate::query()->where('doc_type', 'payslip')->where('is_default', true)->pluck('layout')->all())->toBe(['compact']);
    });

    it('issues every payslip of the month in one go, reports the ones that failed, and prints two per sheet', function () {
        $run = ($this->run)();
        $ids = [...$run->currentItems()->pluck('id')->all(), (string) Str::uuid7()];
        $r = $this->svc->issueMany('payslip', $ids);
        expect($r['documents'])->toHaveCount(2)->and($r['failed'])->toHaveCount(1)->and(array_values($r['failed'])[0])->toContain('not found');
        $pdf = $this->svc->combined($r['documents']);
        expect(substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'))->toBe(1)    // both slips on one A4 sheet
            ->and(DocumentAccessLog::query()->where('action', 'download')->count())->toBe(2)
            ->and(DocumentAccessLog::query()->where('action', 'reprint')->count())->toBe(0);
    });

    it('builds the other documents from their records', function () {
        $run = ($this->run)();
        $this->svc->reprint($this->svc->issue('payroll_register', $run->id), ['language' => 'en']);
        expect(DocumentRenderer::$lastHtml)->toContain($this->a->code)->toContain($this->b->code)
            ->toContain(Money::paise($run->totals['net_paise'])->grouped());

        $snap = PayrollRunAttendance::query()->where('payroll_run_id', $run->id)->where('employee_id', $this->a->id)->sole();
        $this->svc->reprint($this->svc->issue('attendance_summary', $snap->id, ['toggles' => ['calendar' => true]]), ['language' => 'en', 'toggles' => ['calendar' => true]]);
        expect(DocumentRenderer::$lastHtml)->toContain('>WO<')->toContain('>P<');
    });
});

describe('who sees what', function () {
    it('lets an employee open only their own documents', function () {
        $run = ($this->run)();
        $mine = $this->svc->issue('payslip', docItem($run, $this->a)->id);
        $theirs = $this->svc->issue('payslip', docItem($run, $this->b)->id);
        $self = Make::account($this->f->entity, 'EMPLOYEE', ['person' => $this->a->person]);
        $this->f->as($self);
        expect(substr($this->svc->original($mine)['bytes'], 0, 5))->toBe('%PDF-')
            ->and(fn () => $this->svc->original($theirs))->toThrow(BusinessRuleViolation::class, 'only your own')
            ->and(fn () => $this->svc->issue('salary_certificate', $this->a->id))->toThrow(BusinessRuleViolation::class, 'hr.documents.generate');
    });

    it('opens the documents and templates screens for the right roles and prints a payslip from the payroll', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $seen = [];
        foreach (['HR_MANAGER', 'ACCOUNTANT', 'SUPERVISOR', 'EMPLOYEE'] as $role) {
            $a = Make::account($this->f->entity, $role, ['mfa' => true]);
            app('auth')->forgetGuards();
            $this->flushSession();
            $this->actingAs($a, 'web')->withSession(Make::webSession($a));
            $this->f->as($a);
            $seen[$role] = $this->get('/admin/hr/documents')->status().'/'.$this->get('/admin/hr/document-templates')->status();
        }
        expect($seen)->toBe(['HR_MANAGER' => '200/200', 'ACCOUNTANT' => '200/403', 'SUPERVISOR' => '403/403', 'EMPLOYEE' => '403/403']);

        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($this->f->hr, 'web')->withSession(Make::webSession($this->f->hr));
        $this->f->as($this->f->hr);
        $run = ($this->run)();
        $item = docItem($run, $this->a);
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $run, 'pageClass' => ViewPayrollRun::class])
            ->callTableAction('payslip', $item->id, ['language' => 'bilingual', 'paper' => 'half_a4', 'format' => 'pdf', 'digits' => 'western'])
            ->assertDispatched('rm-print')
            ->callTableAction('payslip', $item->id, ['language' => 'bilingual', 'paper' => 'half_a4', 'format' => 'pdf', 'digits' => 'western', 'deliver' => 'download'])
            ->assertFileDownloaded();
        expect(GeneratedDocument::query()->where('source_id', $item->id)->count())->toBe(1);
        Livewire::test(ViewPayrollRun::class, ['record' => $run->id])
            ->callAction('payslips', ['language' => 'bilingual', 'paper' => 'half_a4', 'format' => 'pdf', 'digits' => 'western'])
            ->assertFileDownloaded('payslips-2026-09.pdf')
            ->callAction('registerXlsx')->assertFileDownloaded('payroll-register-2026-09.xlsx');
        expect(GeneratedDocument::query()->where('doc_type', 'payslip')->count())->toBe(2);
        Livewire::test(ListGeneratedDocuments::class)->assertCanSeeTableRecords(GeneratedDocument::query()->get())
            ->callTableAction('reprint', GeneratedDocument::query()->first()->id, ['language' => 'ur', 'paper' => 'a4', 'format' => 'pdf', 'digits' => 'urdu', 'password' => 'none', 'deliver' => 'download'])
            ->assertFileDownloaded();
        Livewire::test(ManageDocumentTemplates::class)->filterTable('doc_type', 'payslip')->assertCanSeeTableRecords(DocumentTemplate::query()->where('doc_type', 'payslip')->get());
    });
});
