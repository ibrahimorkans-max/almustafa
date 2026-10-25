<?php

declare(strict_types=1);

use App\Domain\Approvals\Actions\EmployeeBankAccountChange;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Hr\EmployeeDocuments;
use App\Domain\Hr\EmployeeService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Hr\EmployeeDocument;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Models\Role;
use App\Support\Values\PakistanIds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('HRE'));
});

const PDF_BYTES = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

describe('employee master', function () {
    it('creates an employee as a person with a gap-free code, encrypted identifiers and masked display', function () {
        $cnic = HrFixtures::cnic(123);
        $a = $this->f->employee(['cnic' => substr($cnic, 0, 4).' '.substr($cnic, 4, 4).' '.substr($cnic, 8)]);
        $b = $this->f->employee(['full_name' => 'Ghulam Rasool']);

        expect($a->code)->toBe('HRE-EMP-0001')->and($b->code)->toBe('HRE-EMP-0002')
            ->and($a->person->full_name)->toBe('Muhammad Iqbal')
            ->and($a->cnic_last4)->toBe(substr($cnic, -4))
            ->and($a->maskedCnic())->toBe('XXXXX-XXXX'.substr($cnic, -4, 3).'-'.substr($cnic, -1))
            ->and($a->toArray())->not->toHaveKeys(['cnic', 'ntn', 'date_of_birth', 'address']);

        $raw = Make::system(fn () => DB::table('employees')->where('id', $a->id)->first());
        expect($raw->cnic)->not->toContain($cnic)->and($raw->ntn)->not->toContain($a->ntn)
            ->and($raw->address)->not->toContain('Islampura')->and($raw->date_of_birth)->not->toContain('1990');

        $log = Make::system(fn () => AuditLog::query()->where('subject_id', $a->id)->where('action', 'like', '%created%')->first());
        expect(json_encode($log->new_values))->not->toContain($cnic)->not->toContain('Islampura');
    });

    it('validates CNIC, NTN and age, and keeps CNIC / NTN unique within the entity', function () {
        $svc = app(EmployeeService::class);
        $this->f->as($this->f->hr);
        $base = ['full_name' => 'X Y', 'employment_type' => 'permanent', 'joining_date' => '2026-04-01'];
        $valid = HrFixtures::cnic(77);
        $noRegion = '8'.substr($valid, 1); // CNICs start with a region digit 1–7

        expect(PakistanIds::isValidCnic($valid))->toBeTrue()->and(PakistanIds::isValidCnic($noRegion))->toBeFalse()
            ->and(fn () => $svc->create($base + ['cnic' => $noRegion]))->toThrow(BusinessRuleViolation::class, 'CNIC is not valid')
            ->and(fn () => $svc->create($base + ['cnic' => '36203-123456-1']))->toThrow(BusinessRuleViolation::class, 'CNIC is not valid')
            ->and(fn () => $svc->create($base + ['ntn' => 'ABCDE12345']))->toThrow(BusinessRuleViolation::class, 'NTN is not valid')
            ->and(fn () => $svc->create($base + ['date_of_birth' => now()->subYears(12)->toDateString()]))->toThrow(BusinessRuleViolation::class, 'younger than 15');

        $first = $this->f->employee(['cnic' => $valid]);
        expect(fn () => $this->f->employee(['cnic' => $valid]))->toThrow(BusinessRuleViolation::class, "already recorded for employee {$first->code}");

        // The same person may also work for another firm of the group.
        $other = new HrFixtures(Make::entity('HR2'));
        expect($other->employee(['cnic' => $valid])->code)->toBe('HR2-EMP-0001');
    });

    it('lets HR edit, the supervisor only look, and nobody else touch the master', function () {
        $e = $this->f->employee();
        $this->f->as($this->f->supervisor);
        expect(fn () => app(EmployeeService::class)->update($e, ['notice_period_days' => 15]))->toThrow(BusinessRuleViolation::class, 'hr.employee.edit');
        $this->f->as($this->f->hr);
        $updated = app(EmployeeService::class)->update($e, ['notice_period_days' => 15, 'full_name' => 'Muhammad Iqbal Khan']);
        expect($updated->notice_period_days)->toBe(15)->and($updated->person->full_name)->toBe('Muhammad Iqbal Khan');
    });

    it('reveals full identifiers only with hr.employee.sensitive.view, and audits every reveal', function () {
        $e = $this->f->employee();
        $this->f->as($this->f->accountant);
        expect(fn () => app(EmployeeService::class)->reveal($e, 'cnic'))->toThrow(BusinessRuleViolation::class, 'hr.employee.sensitive.view');

        $this->f->as($this->f->hr);
        expect(app(EmployeeService::class)->reveal($e, 'cnic'))->toHaveLength(13)
            ->and(app(EmployeeService::class)->reveal($e, 'date_of_birth'))->toBe('1990-05-17');
        $reveals = Make::system(fn () => AuditLog::query()->where('action', 'hr.employee.sensitive_revealed')->where('subject_id', $e->id)->get());
        expect($reveals)->toHaveCount(2)->and($reveals->pluck('meta.field')->all())->toBe(['cnic', 'date_of_birth'])
            ->and($reveals->first()->actor_login_account_id)->toBe($this->f->hr->id);
    });
});

describe('exit', function () {
    it('needs notice and a complete clearance, then revokes the ERP login and freezes the record', function () {
        $login = Make::account($this->f->entity, 'GATE_OPERATOR');
        $e = $this->f->employee(['person_id' => $login->person_id, 'full_name' => null]);
        $session = Make::webSession($login);
        $svc = app(EmployeeService::class);
        $this->f->as($this->f->hr);

        expect(fn () => $svc->markExited($e, CarbonImmutable::parse('2026-10-31')))->toThrow(BusinessRuleViolation::class, 'notice first');
        $svc->giveNotice($e, CarbonImmutable::parse('2026-10-01'), 'Moving to his village');
        expect($e->status)->toBe('on_notice')->and($e->last_working_day->toDateString())->toBe('2026-10-31')
            ->and($e->clearanceItems()->count())->toBe(5);
        expect(fn () => $svc->markExited($e, CarbonImmutable::parse('2026-10-31')))->toThrow(BusinessRuleViolation::class, 'Clearance is not complete');

        $e->clearanceItems()->get()->each(fn ($i) => $svc->completeClearance($i, 'done'));
        $svc->markExited($e, CarbonImmutable::parse('2026-10-31'));

        expect($e->fresh()->status)->toBe('exited')
            ->and(Make::system(fn () => $login->fresh()->status))->toBe(LoginAccount::STATUS_REVOKED)
            ->and(fn () => $svc->update($e->fresh(), ['notice_period_days' => 10]))->toThrow(BusinessRuleViolation::class, 'kept as history');
        $this->actingAs($login, 'web')->withSession($session)->get('/admin')->assertRedirect('/admin/login');
    });

    it('does not duplicate the checklist when notice is withdrawn and given again', function () {
        $e = $this->f->employee();
        $svc = app(EmployeeService::class);
        $this->f->as($this->f->hr);
        $svc->giveNotice($e, CarbonImmutable::parse('2026-10-01'), 'Resigned');
        $svc->withdrawNotice($e, 'Stayed on after a raise');
        $svc->giveNotice($e->fresh(), CarbonImmutable::parse('2026-12-01'), 'Resigned again');
        expect($e->clearanceItems()->count())->toBe(5);
    });
});

describe('documents', function () {
    it('stores privately, supersedes older copies, and opens identity documents only with the sensitive permission', function () {
        $e = $this->f->employee();
        $docs = app(EmployeeDocuments::class);
        $this->f->as($this->f->hr);
        $first = $docs->upload($e, 'cnic', '', PDF_BYTES);
        $second = $docs->upload($e, 'cnic', 'CNIC (new)', PDF_BYTES."% v2\n");
        $contract = $docs->upload($e, 'contract', 'Appointment letter', PDF_BYTES."% contract\n");

        expect($first->fresh()->status)->toBe('superseded')->and($first->fresh()->superseded_by)->toBe($second->id)
            ->and(EmployeeDocument::query()->count())->toBe(3)
            ->and(Storage::disk('local')->exists($second->file->path))->toBeTrue()
            ->and($second->file->path)->not->toContain('public');

        $this->f->as($this->f->accountant);
        expect($docs->mayOpen($contract))->toBeTrue()->and($docs->mayOpen($second))->toBeFalse()
            ->and(fn () => $docs->download($second))->toThrow(BusinessRuleViolation::class, 'sensitive');
        expect($docs->download($contract)['bytes'])->toContain('% contract');
        expect(Make::system(fn () => AuditLog::query()->where('action', 'hr.document.downloaded')->count()))->toBe(1);

        $this->f->as($this->f->gate);
        expect($docs->mayOpen($contract))->toBeFalse();
    });

    it('refuses to hand out a stored file that was altered', function () {
        $e = $this->f->employee();
        $this->f->as($this->f->hr);
        $doc = app(EmployeeDocuments::class)->upload($e, 'contract', 'Contract', PDF_BYTES);
        Storage::disk('local')->put($doc->file->path, PDF_BYTES.'tampered');
        expect(fn () => app(EmployeeDocuments::class)->download($doc))->toThrow(BusinessRuleViolation::class, 'may have been altered');
    });

    it('reminds HR 30, 7 and 0 days before a document expires, once each', function () {
        $e = $this->f->employee();
        $this->f->as($this->f->hr);
        $today = now()->timezone('Asia/Karachi')->startOfDay();
        app(EmployeeDocuments::class)->upload($e, 'contract', 'Contract', PDF_BYTES, CarbonImmutable::instance($today)->addDays(30));
        app(EmployeeDocuments::class)->upload($e, 'id_proof', 'Driving licence', PDF_BYTES.'dl', CarbonImmutable::instance($today)->addDays(7));
        app(EmployeeDocuments::class)->upload($e, 'other', 'Medical certificate', PDF_BYTES.'med', CarbonImmutable::instance($today)->addDays(12));

        $this->artisan('hr:document-expiry')->expectsOutputToContain('2 expiry reminder(s)')->assertSuccessful();
        $this->artisan('hr:document-expiry')->expectsOutputToContain('0 expiry reminder(s)');
        $alerts = Make::system(fn () => Alert::query()->where('type', 'like', 'hr_document_expiry_%')->pluck('title')->all());
        expect($alerts)->toHaveCount(2)->and(implode('|', $alerts))->toContain('expires in 30 days')->toContain('expires in 7 days');
    });
});

describe('salary account (maker-checker)', function () {
    it('changes the salary account only after someone who approves payroll agrees', function () {
        $e = $this->f->employee();
        $this->f->as($this->f->hr);
        $req = app(ApprovalService::class)->submit(EmployeeBankAccountChange::KEY,
            ['employee_id' => $e->id, 'account_holder' => 'Muhammad Iqbal', 'account_number' => 'pk 27 habb 0000 0050 1001 3456'],
            'Salary account', 'Joining');

        expect($e->fresh()->bank_account_last4)->toBeNull()
            ->and(json_encode(Make::system(fn () => DB::table('approval_requests')->where('id', $req->id)->value('payload'))))->not->toContain('0000005010013456')
            ->and(app(ApprovalService::class)->decisionBlocker($this->f->hr, $req))->toContain('cannot approve your own request')
            ->and(app(ApprovalService::class)->decisionBlocker($this->f->supervisor, $req))->not->toBeNull();

        // The Accountant holds hr.payroll.approve and, as a main user, approval.decide: it may approve too.
        expect(app(ApprovalService::class)->decisionBlocker($this->f->accountant, $req))->toBeNull();
        $this->f->as($this->f->owner);
        $done = app(ApprovalService::class)->approve($req, $this->f->owner, 'Checked against the passbook');
        $fresh = $e->fresh();
        expect($done->status)->toBe(ApprovalRequest::STATUS_EXECUTED)
            ->and($fresh->bank_account_last4)->toBe('3456')->and($fresh->bank_code)->toBe('HABB')
            ->and($fresh->maskedBankAccount())->toBe('PK** **** **** **** 3456 · HABB');
        $log = Make::system(fn () => AuditLog::query()->where('action', 'hr.employee.bank_account_changed')->sole());
        expect(json_encode($log->meta))->not->toContain('0000005010013456')->toContain('XXXXXX3456');
    });
});

it('creates missing template roles such as HR Manager in existing entities on access:sync', function () {
    $roleId = Make::system(fn () => Role::query()->where('entity_id', $this->f->entity->id)->where('code', 'HR_MANAGER')->value('id'));
    Make::system(function () use ($roleId) {
        DB::table('login_accounts')->where('role_id', $roleId)->delete();
        DB::table('role_permissions')->where('role_id', $roleId)->delete();
        DB::table('roles')->where('id', $roleId)->delete();
    });

    $this->artisan('access:sync')->assertSuccessful();
    $role = Make::system(fn () => Role::query()->where('entity_id', $this->f->entity->id)->where('code', 'HR_MANAGER')->first());
    expect($role)->not->toBeNull()->and($role->mfa_required)->toBeTrue()
        ->and(Make::system(fn () => $role->permissionCodes()))->toContain('hr.salary.edit', 'hr.employee.sensitive.view')
        ->and(Make::system(fn () => Person::query()->count()))->toBeGreaterThan(0);
});
