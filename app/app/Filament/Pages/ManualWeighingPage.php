<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\RoleTemplates;
use App\Domain\Audit\AuditLogger;
use App\Domain\Gate\ManualWeighing;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\Role;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Gate → Manual weighing (the Owner only): whether a vehicle's weight may be typed by hand, whether both of its
 * weights may be typed, and the people (ticked) who may do it (App\Domain\Gate\ManualWeighing).
 *
 * @property Form $form
 */
class ManualWeighingPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Manual weighing';

    protected static ?string $title = 'Manual weighing';

    protected static ?string $slug = 'gate/manual-weighing';

    protected static ?int $navigationSort = 75;

    protected static string $view = 'filament.pages.manual-weighing';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isOwner() && ! $user->isPlatformAdmin();
    }

    /** One firm chosen (not "All firms"): its setting is shown. */
    public function firm(): ?string
    {
        $id = app(TenantContext::class)->entityId();

        return filled($id) ? $id : null;
    }

    public function mount(): void
    {
        $entityId = $this->firm();
        if ($entityId === null) {
            return;
        }
        $this->form->fill([
            'enabled' => ManualWeighing::enabled($entityId),
            'both' => ManualWeighing::bothAllowed($entityId),
            'users' => ManualWeighing::users($entityId),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Weights typed by hand')
                ->description('Every typed weight keeps its reason (and the other weighbridge\'s slip when given), is marked "typed by hand" everywhere and goes to the weight checks.')
                ->schema([
                    Toggle::make('enabled')->label('Allow weights to be typed by hand')->live()
                        ->helperText('Off: every weight must come from the weighbridge.'),
                    Toggle::make('both')->label('Allow both weights of a vehicle to be typed (loaded and empty)')
                        ->visible(fn (Get $get) => (bool) $get('enabled'))
                        ->helperText('For when the weighbridge is out of order. Off: only one of the two may be typed; the other must come from the weighbridge.'),
                ]),
            Section::make('Who may type weights')
                ->description('Tick the people who may type weights by hand. Nobody ticked: those whose role allows it (Supervisor, Super Admin, Owner).')
                ->visible(fn (Get $get) => (bool) $get('enabled'))
                ->schema([
                    CheckboxList::make('users')->hiddenLabel()->options(fn () => self::staff())->columns(2)->searchable()->bulkToggleable()
                        ->noSearchResultsMessage('Nobody of that name.'),
                ]),
        ]);
    }

    /** @return array<string, string> the mill's active staff sign-ins: "Name (username) · Role" */
    public static function staff(): array
    {
        $roles = Role::query()->pluck('name', 'id');
        $partyRoles = Role::query()->where('code', RoleTemplates::PARTY)->pluck('id')->all();

        return LoginAccount::query()->with('person')->where('entity_id', app(TenantContext::class)->entityId())
            ->where('status', LoginAccount::STATUS_ACTIVE)->whereNotIn('role_id', $partyRoles)->orderBy('username')->get()
            ->mapWithKeys(fn (LoginAccount $a) => [$a->id => trim(($a->person->full_name ?? $a->username).' ('.$a->username.') · '.($roles[$a->role_id] ?? ''))])
            ->all();
    }

    public function save(): void
    {
        abort_unless(self::canAccess() && $this->firm() !== null, 403);
        $data = $this->form->getState();
        $users = array_values(array_intersect((array) ($data['users'] ?? []), array_keys(self::staff())));
        $entityId = (string) $this->firm();
        ManualWeighing::save($entityId, (bool) ($data['enabled'] ?? false), (bool) ($data['both'] ?? false), $users);
        app(AuditLogger::class)->record('gate.manual_weighing.set', null, null, null, [
            'enabled' => (bool) ($data['enabled'] ?? false), 'both' => (bool) ($data['both'] ?? false), 'users' => count($users),
        ]);
        Notification::make()->success()->title(! ($data['enabled'] ?? false) ? 'Weights can no longer be typed by hand.'
            : (($data['both'] ?? false) ? 'Both weights may be typed by hand' : 'One of the two weights may be typed by hand').
              ($users === [] ? ', by those whose role allows it.' : ', by the '.count($users).' people ticked.'))->send();
    }
}
