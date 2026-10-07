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

it('lets a Super Admin create an entity from the admin panel, storing GSTIN/PAN encrypted', function () {
    $home = Make::entity('HQ');
    $sa = Make::superAdmin($home);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($sa, 'web')->withSession(Make::webSession($sa));
    app(AuthContextBinder::class)->bind($sa);

    Livewire::test(CreateEntity::class)
        ->fillForm([
            'code' => 'PNP', 'name' => 'Panipat Mill', 'gstin' => '06abcde1234f1z5', 'pan' => 'ABCDE1234F',
            'state_code' => '06', 'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $entity = Entity::query()->where('code', 'PNP')->sole();
    $raw = Make::system(fn () => DB::table('entities')->where('id', $entity->id)->first());

    expect($entity->gstin)->toBe('06ABCDE1234F1Z5')
        ->and($raw->gstin)->not->toContain('06ABCDE1234F1Z5')
        ->and($raw->pan)->not->toContain('ABCDE1234F')
        ->and(Make::system(fn () => Role::query()->where('entity_id', $entity->id)->count()))->toBe(count(RoleTemplates::entityRoleCodes()))
        ->and(Make::system(fn () => AuditLog::query()->where('action', 'entity.created')->where('subject_id', $entity->id)->first()?->new_values['gstin']))
        ->toBe('***********F1Z5');
});

it('does not let an Owner create entities', function () {
    $entity = Make::entity('OWN');
    $owner = Make::account($entity, 'OWNER');

    $this->actingAs($owner, 'web')->withSession(Make::webSession($owner))
        ->get('/admin/entities/create')->assertForbidden();
});
