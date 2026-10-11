<?php

declare(strict_types=1);

use App\Domain\Alerts\AlertService;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\Engine\DocumentBatches;
use App\Domain\Documents\Engine\DocumentService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\AlertResource;
use App\Filament\Resources\AlertResource\Pages\ListAlerts;
use App\Models\Alert;
use App\Models\Documents\DocumentBatch;
use App\Models\Documents\GeneratedDocument;
use App\Models\LoginAccount;
use App\Models\Role;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

/**
 * Who opens which HR and accounts screen (phases 1–11), role by role. Y = opens, . = refused with the
 * explaining 403 page. Employees work in the phone app and "My documents", so nothing here opens for an
 * employee login without an employee record. Change a cell only together with the role it belongs to.
 */
const HR_MATRIX = [
    //                                  OWN  EXE  ACC  HR   SUP  INV  GAT  LAB  EMP
    '/admin/hr/employees' => 'Y . Y Y Y . . . .',
    '/admin/hr/attendance' => 'Y . Y Y Y . . . .',
    '/admin/hr/attendance-register' => 'Y . Y Y Y . . . .',
    '/admin/hr/leave-requests' => 'Y . Y Y Y . . . .',
    '/admin/hr/employee-devices' => 'Y . Y Y Y . . . .',
    '/admin/hr/advances' => 'Y . Y Y . . . . .',
    '/admin/hr/piece-work' => 'Y . Y Y Y . . . .',
    '/admin/hr/allowance-rules' => 'Y . Y Y . . . . .',
    '/admin/hr/pay-adjustments' => 'Y . Y Y . . . . .',
    '/admin/hr/payroll' => 'Y . Y Y . . . . .',
    '/admin/hr/payroll-settings' => 'Y . Y Y . . . . .',
    '/admin/hr/statutory-reports' => 'Y . Y Y . . . . .',
    '/admin/hr/documents' => 'Y . Y Y . . . . .',
    '/admin/hr/document-templates' => 'Y . . Y . . . . .',
    '/admin/hr/document-batches' => 'Y . Y Y . . . . .',
    '/admin/my-documents' => '. . . . . . . . .',
    '/admin/hr/staff-claims' => 'Y . Y Y Y . . . .',
    '/admin/hr/contractor-bills' => 'Y . Y Y . . . . .',
    '/admin/expense-categories' => 'Y . Y . . . . . .',
    '/admin/expenses' => 'Y . Y . . . . . .',
    '/admin/recurring-expenses' => 'Y . Y . . . . . .',
    '/admin/profit-and-loss' => 'Y . Y . . . . . .',
    '/admin/alerts' => 'Y Y Y Y Y Y . Y .',
];

const HR_MATRIX_ROLES = ['OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'HR_MANAGER', 'SUPERVISOR', 'INVENTORY_MANAGER', 'GATE_OPERATOR', 'LAB_TECHNICIAN', 'EMPLOYEE'];

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function mxSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

it('opens each HR and accounts screen for exactly the roles in the matrix', function () {
    $entity = Make::entity('MTX');
    $actual = array_fill_keys(array_keys(HR_MATRIX), []);
    foreach (HR_MATRIX_ROLES as $role) {
        mxSignIn($this, Make::account($entity, $role, ['mfa' => true]));
        foreach (array_keys(HR_MATRIX) as $url) {
            $status = $this->get($url)->status();
            $actual[$url][] = match ($status) {
                200 => 'Y', 403 => '.', default => (string) $status,
            };
        }
    }
    expect(array_map(fn (array $cells) => implode(' ', $cells), $actual))->toBe(HR_MATRIX);
});

it('shows each person only the alerts raised for their permissions', function () {
    $f = new HrFixtures(Make::entity('MXA'));
    $store = Make::account($f->entity, 'INVENTORY_MANAGER', ['mfa' => true]);
    $f->as($f->owner);
    $alerts = app(AlertService::class);
    $alerts->raise('minimum_wage_shortfall', 'warning', 'Payroll: 2 below the minimum wage', 'Names and pay', null, [], 'hr.salary.view');
    $alerts->raise('shrinkage', 'warning', 'Silo shrinkage above limit', 'Stock', null, [], 'stock.view');
    Alert::query()->create(['type' => 'ops', 'title' => 'Backup overdue', 'data' => []]);

    $visible = fn (LoginAccount $a) => Alert::query()->visibleTo($a)->orderBy('title')->pluck('title')->all();
    expect($visible($store))->toBe(['Backup overdue', 'Silo shrinkage above limit'])
        ->and($visible($f->hr))->toBe(['Backup overdue', 'Payroll: 2 below the minimum wage'])
        ->and($visible($f->owner))->toHaveCount(3);

    mxSignIn($this, $store);
    Livewire::test(ListAlerts::class)->assertSee('Silo shrinkage above limit')->assertDontSee('below the minimum wage');
    expect(AlertResource::getNavigationBadge())->toBe('2');
    mxSignIn($this, $f->hr);
    Livewire::test(ListAlerts::class)->assertSee('below the minimum wage')->assertDontSee('Silo shrinkage');
});

it('keeps other people\'s pay from a role that was given documents without salaries', function () {
    $f = new HrFixtures(Make::entity('MXD'));
    $someone = $f->employee();
    // A custom role an owner might make: may produce letters and certificates, not see salaries.
    $clerk = Make::system(function () use ($f): LoginAccount {
        $role = Role::query()->create(['entity_id' => $f->entity->id, 'code' => 'DOC_CLERK', 'name' => 'Document clerk', 'is_system' => false, 'mfa_required' => false]);
        $role->permissions()->attach(['hr.documents.generate', 'hr.employee.view']);

        return Make::account($f->entity, 'DOC_CLERK');
    });
    $docs = app(DocumentService::class);
    $doc = fn (string $type) => new GeneratedDocument(['doc_type' => $type, 'employee_id' => $someone->id]);

    $f->as($clerk);
    expect(fn () => $docs->authorizeView($doc('payslip')))->toThrow(BusinessRuleViolation::class, 'hr.salary.view')
        ->and(fn () => $docs->authorizeView($doc('salary_certificate')))->toThrow(BusinessRuleViolation::class, 'hr.salary.view')
        ->and(fn () => $docs->requireFor('payslip'))->toThrow(BusinessRuleViolation::class, 'hr.salary.view')
        ->and(fn () => app(DocumentBatches::class)->start('payslip', ['x'], 'Payslips'))->toThrow(BusinessRuleViolation::class, 'hr.salary.view');
    $docs->authorizeView($doc('employment_letter'));
    $docs->requireFor('employment_letter');

    $f->as($f->hr);
    $docs->authorizeView($doc('payslip'));
    $f->as($f->supervisor);
    expect(fn () => $docs->authorizeView($doc('employment_letter')))->toThrow(BusinessRuleViolation::class, 'only your own');
});

it('limits a bulk run to 1,000 documents and two runs at a time', function () {
    $f = new HrFixtures(Make::entity('MXB'));
    $f->as($f->hr);
    $bulk = app(DocumentBatches::class);
    expect(fn () => $bulk->start('employment_letter', array_map(fn (int $i) => "id-{$i}", range(1, 1001)), 'Too many'))
        ->toThrow(BusinessRuleViolation::class, 'At most 1000');

    foreach ([1, 2] as $i) {
        DocumentBatch::query()->create(['doc_type' => 'employment_letter', 'label' => "Running {$i}", 'source_ids' => ['a'], 'options' => [], 'total' => 1]);
    }
    expect(fn () => $bulk->start('employment_letter', [$f->employee()->id], 'Third'))->toThrow(BusinessRuleViolation::class, 'Two bulk runs');

    // A run nobody has touched for two hours (its worker died) no longer blocks.
    DocumentBatch::query()->where('label', 'Running 1')->update(['updated_at' => now()->subHours(3)]);
    expect($bulk->start('employment_letter', [$f->employee()->id], 'Third')->total)->toBe(1);
});
