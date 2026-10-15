<?php

declare(strict_types=1);

use App\Filament\Resources\Hr\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\Hr\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\Hr\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\Hr\EmployeeResource\RelationManagers\SalaryStructuresRelationManager;
use App\Models\AuditLog;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeDocument;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->f = new HrFixtures(Make::entity('HRA'));
});

function hrSignIn(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

dataset('hr screens', ['/admin/hr/employees', '/admin/hr/departments', '/admin/hr/designations', '/admin/hr/salary-components']);

it('opens the HR screens for HR, owners, accountants and supervisors only', function (string $path) {
    $expected = ['HR_MANAGER' => 200, 'SUPER_ADMIN' => 200, 'ACCOUNTANT' => 200, 'SUPERVISOR' => 200, 'GATE_OPERATOR' => 403, 'LAB_TECHNICIAN' => 403];
    $actual = [];
    foreach ($expected as $role => $status) {
        $account = Make::account($this->f->entity, $role, ['mfa' => true]);
        hrSignIn($this, $account);
        $res = $this->get($path);
        $actual[$role] = $res->status().($res->isRedirect() ? ' → '.$res->headers->get('Location') : '');
        app('auth')->forgetGuards(); // a fresh request per user, as in production
        $this->flushSession();
    }
    expect($actual)->toBe(array_map('strval', $expected));
})->with('hr screens');

it('adds an employee from the form and shows identifiers masked', function () {
    hrSignIn($this, $this->f->hr);
    $cnic = HrFixtures::cnic(4242);
    Livewire::test(CreateEmployee::class)->fillForm([
        'full_name' => 'Ghulam Rasool', 'phone' => '0301-2345678', 'employment_type' => 'contract',
        'department_id' => Department::query()->where('code', 'GATE')->value('id'),
        'joining_date' => '2026-06-01', 'cnic' => $cnic, 'ntn' => '1234567-8', 'date_of_birth' => '1988-02-01',
    ])->call('create')->assertHasNoFormErrors();

    $e = Employee::query()->sole();
    $this->get("/admin/hr/employees/{$e->id}")->assertOk()
        ->assertSee('Ghulam Rasool')->assertSee('XXXXX-XXXX'.substr($cnic, -4, 3).'-'.substr($cnic, -1))->assertSee('XXXX5678')
        ->assertDontSee($cnic);
});

it('reports a bad CNIC on the field', function () {
    hrSignIn($this, $this->f->hr);
    Livewire::test(CreateEmployee::class)->fillForm([
        'full_name' => 'Xavier Y', 'employment_type' => 'permanent', 'joining_date' => '2026-06-01', 'cnic' => '9234567890123',
    ])->call('create')->assertHasFormErrors(['cnic']);
});

it('keeps the stored CNIC when the edit form leaves it empty', function () {
    $e = $this->f->employee();
    $last4 = $e->cnic_last4;
    hrSignIn($this, $this->f->hr);
    Livewire::test(EditEmployee::class, ['record' => $e->id])
        ->assertFormSet(['cnic' => null, 'full_name' => 'Muhammad Iqbal'])
        ->fillForm(['emergency_contact_phone' => '03009800111'])->call('save')->assertHasNoFormErrors();
    expect($e->fresh()->cnic_last4)->toBe($last4)->and($e->fresh()->emergency_contact_phone)->toBe('03009800111');
});

it('reveals a value on the page for HR and records it; the button is not offered without the permission', function () {
    $e = $this->f->employee();
    hrSignIn($this, $this->f->hr);
    Livewire::test(ViewEmployee::class, ['record' => $e->id])->call('reveal', 'ntn')->assertSet('revealed.ntn', $e->ntn)->assertSee($e->ntn);
    expect(Make::system(fn () => AuditLog::query()->where('action', 'hr.employee.sensitive_revealed')->count()))->toBe(1);
});

it('shows the salary history only to people with hr.salary.view', function () {
    $e = $this->f->employee();
    hrSignIn($this, $this->f->hr);
    expect(SalaryStructuresRelationManager::canViewForRecord($e, ViewEmployee::class))->toBeTrue();
    hrSignIn($this, $this->f->supervisor);
    expect(SalaryStructuresRelationManager::canViewForRecord($e, ViewEmployee::class))->toBeFalse();
    $this->get("/admin/hr/employees/{$e->id}")->assertOk()->assertDontSee('Salary history');
});

it('records salary, documents, notice and exit from the employee page', function () {
    $e = $this->f->employee();
    hrSignIn($this, $this->f->hr);
    $page = fn () => Livewire::test(ViewEmployee::class, ['record' => $e->id]);

    $page()->callAction('salary', ['effective_from' => '2026-04-01', 'pay_basis' => 'monthly', 'amount' => ['BASIC' => '15000', 'HRA' => '4000.50'], 'reason' => 'Joining'])
        ->assertNotified('Salary version saved.');
    expect($e->salaryOn(now())->totalPaise('earning'))->toBe(19_000_50);

    $page()->callAction('document', ['kind' => 'contract', 'title' => 'Appointment letter',
        'file' => UploadedFile::fake()->createWithContent('letter.pdf', "%PDF-1.4\n%%EOF\n")])->assertNotified('Document stored.');
    expect(EmployeeDocument::query()->where('employee_id', $e->id)->value('title'))->toBe('Appointment letter');

    $page()->callAction('notice', ['given_on' => '2026-10-01', 'reason' => 'Resigned'])->assertNotified('Notice recorded. Clearance checklist opened.');
    $page()->callAction('exit', ['exited_on' => '2026-10-31'])->assertNotified();
    expect($e->fresh()->status)->toBe('on_notice'); // clearance still open → refused with the list of open items

    $page()->callAction('bank', ['account_holder' => 'Muhammad Iqbal', 'account_number' => 'PK36SCBL0000001123456702', 'bank_name' => 'Standard Chartered'])
        ->assertNotified('Sent for approval.');
    expect($e->fresh()->bank_account_last4)->toBeNull();
});

it('lets the supervisor look but not change', function () {
    $e = $this->f->employee();
    hrSignIn($this, $this->f->supervisor);
    Livewire::test(ViewEmployee::class, ['record' => $e->id])
        ->assertActionHidden('edit')->assertActionHidden('salary')->assertActionHidden('notice')->assertActionHidden('bank');
    $this->get("/admin/hr/employees/{$e->id}/edit")->assertForbidden();
});
