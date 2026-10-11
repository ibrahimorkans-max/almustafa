<?php

declare(strict_types=1);

use App\Domain\Access\RoleTemplates;
use App\Domain\Auth\AuthContextBinder;
use App\Filament\Resources\EntityResource\Pages\CreateEntity;
use App\Models\AuditLog;
use App\Models\Entity;
use App\Models\Role;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Make;

it('creates an entity with every default role and its grants', function () {
    $entity = Make::entity('NEW');

    $roles = Make::system(fn () => Role::query()->where('entity_id', $entity->id)->get()->keyBy('code'));

    expect($roles->keys()->sort()->values()->all())->toBe(collect(RoleTemplates::entityRoleCodes())->sort()->values()->all());
    foreach (RoleTemplates::entityRoleCodes() as $code) {
        expect($roles[$code]->permissionCodes())->toEqualCanonicalizing(RoleTemplates::get($code)['permissions'])
            ->and($roles[$code]->mfa_required)->toBe(RoleTemplates::get($code)['mfa_required']);
    }
});

it('lets a Super Admin create an entity from the admin panel, storing NTN/STRN encrypted', function () {
    $home = Make::entity('HQ');
    $sa = Make::superAdmin($home);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($sa, 'web')->withSession(Make::webSession($sa));
    app(AuthContextBinder::class)->bind($sa);

    Livewire::test(CreateEntity::class)
        ->fillForm([
            'code' => 'LDN', 'name' => 'Lodhran Mill', 'ntn' => '1234567-8', 'strn' => '32-77-8765-432-10',
            'province' => 'Punjab', 'city' => 'Lodhran', 'phone' => '0300-6834588', 'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $entity = Entity::query()->where('code', 'LDN')->sole();
    $raw = Make::system(fn () => DB::table('entities')->where('id', $entity->id)->first());

    expect($entity->ntn)->toBe('12345678')
        ->and($entity->strn)->toBe('3277876543210')
        ->and($entity->province)->toBe('Punjab')
        ->and($raw->strn)->not->toContain('3277876543210')
        ->and($raw->ntn)->not->toContain('12345678')
        ->and(Make::system(fn () => Role::query()->where('entity_id', $entity->id)->count()))->toBe(count(RoleTemplates::entityRoleCodes()))
        ->and(Make::system(fn () => AuditLog::query()->where('action', 'entity.created')->where('subject_id', $entity->id)->first()?->new_values['strn']))
        ->toBe('*********3210');
});

it('does not let an Owner create entities', function () {
    $entity = Make::entity('OWN');
    $owner = Make::account($entity, 'OWNER');

    $this->actingAs($owner, 'web')->withSession(Make::webSession($owner))
        ->get('/admin/entities/create')->assertForbidden();
});
