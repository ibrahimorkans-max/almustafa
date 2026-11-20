<?php

declare(strict_types=1);

use App\Domain\Access\RoleTwoFactor;
use App\Domain\Auth\AuthContextBinder;
use App\Filament\Pages\TwoFactor;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\RoleResource\Pages\ViewRole;
use App\Models\AuditLog;
use App\Models\LoginAccount;
use App\Models\Role;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('RTF');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->owner = Make::account($this->entity, 'OWNER', ['mfa' => true]);
    $this->gateRole = Make::system(fn () => Role::query()->where('entity_id', $this->entity->id)->where('code', 'GATE_OPERATOR')->firstOrFail());
});

function rtfSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

it('lets the Owner require 2FA for a role; its people then set up their own authenticator', function () {
    $clerk = Make::account($this->entity, 'GATE_OPERATOR');
    expect($this->gateRole->mfa_required)->toBeFalse();

    rtfSignIn($this, $this->owner);
    Livewire::test(ViewRole::class, ['record' => $this->gateRole->id])
        ->assertActionVisible('twoFactor')
        ->callAction('twoFactor')->assertHasNoActionErrors()->assertNotified('2FA is now required for this role.');

    expect(Make::system(fn () => $this->gateRole->fresh()->mfa_required))->toBeTrue()
        ->and(Make::system(fn () => AuditLog::query()->where('action', 'role.mfa_required')->sole()->new_values))->toBe(['mfa_required' => true]);

    // The gate clerk is sent to set up the authenticator before any other screen.
    rtfSignIn($this, Make::system(fn () => $clerk->fresh(['role'])));
    $this->get(GateEntryResource::getUrl())->assertRedirect(TwoFactor::getUrl());
});

it('needs a reason to stop requiring 2FA', function () {
    Make::system(fn () => $this->gateRole->forceFill(['mfa_required' => true])->save());
    rtfSignIn($this, $this->owner);

    Livewire::test(ViewRole::class, ['record' => $this->gateRole->id])
        ->callAction('twoFactor', ['reason' => ''])->assertHasActionErrors(['reason' => 'required'])
        ->callAction('twoFactor', ['reason' => 'Shared gate PC, no phones'])->assertHasNoActionErrors();

    expect(Make::system(fn () => $this->gateRole->fresh()->mfa_required))->toBeFalse()
        ->and(Make::system(fn () => AuditLog::query()->where('action', 'role.mfa_required')->sole()->meta['reason']))->toBe('Shared gate PC, no phones');
});

it('is for the Owner and Super Admin only, and never for the Owner role itself', function () {
    $supervisor = Make::account($this->entity, 'SUPERVISOR');
    Make::system(fn () => DB::table('role_permissions')->insert([
        'role_id' => $supervisor->role_id, 'permission_code' => 'role.manage', 'entity_id' => $this->entity->id, 'created_at' => now(),
    ]));
    $supervisor = Make::system(fn () => $supervisor->fresh(['role']));

    expect(RoleTwoFactor::canChange($supervisor, $this->gateRole))->toBeFalse()
        ->and(RoleTwoFactor::canChange($this->owner, Make::system(fn () => $this->owner->role)))->toBeFalse()
        ->and(RoleTwoFactor::canChange($this->owner, $this->gateRole))->toBeTrue();
});
