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
    $expected = ['HR_MANAGER' => 200, 'OWNER' => 200, 'ACCOUNTANT' => 200, 'SUPERVISOR' => 200, 'GATE_OPERATOR' => 403, 'LAB_TECHNICIAN' => 403];
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
    $aadhaar = HrFixtures::aadhaar(4242);
    Livewire::test(CreateEmployee::class)->fillForm([
        'full_name' => 'Gurpreet Singh', 'phone' => '9876543210', 'employment_type' => 'contract',
        'department_id' => Department::query()->where('code', 'GATE')->value('id'),
        'joining_date' => '2026-06-01', 'aadhaar' => $aadhaar, 'pan' => 'ABCPS1234K', 'date_of_birth' => '1988-02-01',
    ])->call('create')->assertHasNoFormErrors();

    $e = Employee::query()->sole();
    $this->get("/admin/hr/employees/{$e->id}")->assertOk()
        ->assertSee('Gurpreet Singh')->assertSee('XXXX XXXX '.substr($aadhaar, -4))->assertSee('XXXXXX234K')
        ->assertDontSee($aadhaar);
});

it('reports a bad Aadhaar on the field', function () {
    hrSignIn($this, $this->f->hr);
    Livewire::test(CreateEmployee::class)->fillForm([
        'full_name' => 'Xavier Y', 'employment_type' => 'permanent', 'joining_date' => '2026-06-01', 'aadhaar' => '234567890123',
    ])->call('create')->assertHasFormErrors(['aadhaar']);
});

it('keeps the stored Aadhaar when the edit form leaves it empty', function () {
    $e = $this->f->employee();
    $last4 = $e->aadhaar_last4;
    hrSignIn($this, $this->f->hr);
    Livewire::test(EditEmployee::class, ['record' => $e->id])
        ->assertFormSet(['aadhaar' => null, 'full_name' => 'Ramesh Kumar'])
        ->fillForm(['emergency_contact_phone' => '9800011111'])->call('save')->assertHasNoFormErrors();
    expect($e->fresh()->aadhaar_last4)->toBe($last4)->and($e->fresh()->emergency_contact_phone)->toBe('9800011111');
});

it('reveals a value on the page for HR and records it; the button is not offered without the permission', function () {
    $e = $this->f->employee();
    hrSignIn($this, $this->f->hr);
    Livewire::test(ViewEmployee::class, ['record' => $e->id])->call('reveal', 'pan')->assertSet('revealed.pan', $e->pan)->assertSee($e->pan);
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

    $page()->callAction('bank', ['account_holder' => 'Ramesh Kumar', 'account_number' => '123456789012', 'ifsc' => 'SBIN0001234'])
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
