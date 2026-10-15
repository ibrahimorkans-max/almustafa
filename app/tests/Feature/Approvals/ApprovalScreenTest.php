<?php

declare(strict_types=1);

use App\Domain\Approvals\Actions\ChangeRolePermissions;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\AuthContextBinder;
use App\Filament\Resources\ApprovalRequestResource\Pages\ListApprovalRequests;
use App\Filament\Resources\ApprovalRequestResource\Pages\ViewApprovalRequest;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use App\Models\Role;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->entity = Make::entity('APS');
    $this->maker = Make::account($this->entity, 'SUPER_ADMIN');
    $this->checker = Make::account($this->entity, 'SUPER_ADMIN');
    $role = Make::system(fn () => Role::query()->where('entity_id', $this->entity->id)->where('code', 'GATE_OPERATOR')->firstOrFail());
    app(AuthContextBinder::class)->bind($this->maker);
    $this->request = app(ApprovalService::class)->submit(ChangeRolePermissions::KEY,
        ['role_id' => $role->id, 'permissions' => [...$role->permissionCodes(), 'lab.test']], 'Gate needs lab', 'Night shift');
    $this->role = $role;
});

function signInApprovals(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    app(AuthContextBinder::class)->bind($account);
}

it('shows the requester a disabled Approve button that says why', function () {
    signInApprovals($this, $this->maker);
    $this->get("/admin/approvals/{$this->request->id}")->assertOk()
        ->assertSee('You cannot approve your own request');

    Livewire::test(ViewApprovalRequest::class, ['record' => $this->request->id])
        ->assertActionVisible('approve')->assertActionDisabled('approve')
        ->assertActionVisible('reject')->assertActionDisabled('reject')
        ->callAction('approve', ['note' => 'mine']);
    expect($this->request->fresh()->status)->toBe('pending');
});

it('explains a pending account setup instead of hiding the buttons', function () {
    $fresh = Make::account($this->entity, 'SUPER_ADMIN', ['must_change_password' => true]);
    expect(app(ApprovalService::class)->decisionBlocker($fresh, $this->request))->toContain('Finish your account setup');
    $exec = Make::account($this->entity, 'EXECUTIVE');
    expect(app(ApprovalService::class)->decisionBlocker($exec, $this->request))->toContain("'approval.decide'");
});

it('approves from the page with a note, executes, notifies the requester and shows the history', function () {
    signInApprovals($this, $this->checker);
    Livewire::test(ViewApprovalRequest::class, ['record' => $this->request->id])
        ->assertActionEnabled('approve')
        ->callAction('approve', ['note' => 'Checked with the shift in-charge'])
        ->assertHasNoActionErrors();

    $r = $this->request->fresh();
    expect($r->status)->toBe(ApprovalRequest::STATUS_EXECUTED)
        ->and($r->checker_login_account_id)->toBe($this->checker->id)
        ->and($r->decision_note)->toBe('Checked with the shift in-charge')
        ->and($this->role->fresh()->permissionCodes())->toContain('lab.test');

    $note = DB::table('notifications')->where('notifiable_id', $this->maker->id)->first();
    expect($note)->not->toBeNull()->and($note->data)->toContain('executed');

    $this->get("/admin/approvals/{$r->id}")->assertOk()
        ->assertSee('submitted')->assertSee('approved')->assertSee('executed')
        ->assertSee('Checked with the shift in-charge');

    // Decided requests are immutable: no decision buttons any more, and the service refuses.
    Livewire::test(ViewApprovalRequest::class, ['record' => $r->id])->assertActionHidden('approve')->assertActionHidden('reject');
    expect(fn () => app(ApprovalService::class)->reject($r, $this->checker, 'changed my mind'))->toThrow(Exception::class);
});

it('needs a reason to reject, and notifies the requester', function () {
    signInApprovals($this, $this->checker);
    Livewire::test(ViewApprovalRequest::class, ['record' => $this->request->id])
        ->callAction('reject', ['note' => ''])->assertHasActionErrors(['note' => 'required'])
        ->callAction('reject', ['note' => 'Lab tests stay with the lab'])->assertHasNoActionErrors();

    expect($this->request->fresh()->status)->toBe('rejected')
        ->and($this->role->fresh()->permissionCodes())->not->toContain('lab.test')
        ->and(DB::table('notifications')->where('notifiable_id', $this->maker->id)->value('data'))->toContain('rejected');
});

it('approves from the list row', function () {
    signInApprovals($this, $this->checker);
    Livewire::test(ListApprovalRequests::class)
        ->assertTableActionVisible('approve', $this->request)
        ->callTableAction('approve', $this->request, ['note' => 'ok']);
    expect($this->request->fresh()->status)->toBe('executed');
});

it('applies the self-approval rule to a Super Admin too', function () {
    $sa = Make::owner($this->entity);
    app(AuthContextBinder::class)->bind($sa);
    $own = app(ApprovalService::class)->submit(ChangeRolePermissions::KEY,
        ['role_id' => $this->role->id, 'permissions' => [...$this->role->permissionCodes(), 'stock.view']], 'Gate sees stock');
    expect(app(ApprovalService::class)->decisionBlocker($sa, $own))->toContain('cannot approve your own request')
        ->and(app(ApprovalService::class)->decisionBlocker($sa, $this->request))->toBeNull();
});
