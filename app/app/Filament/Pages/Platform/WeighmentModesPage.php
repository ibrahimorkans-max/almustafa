<?php

declare(strict_types=1);

namespace App\Filament\Pages\Platform;

use App\Domain\Gate\LabSetup;
use App\Domain\Gate\WeighModes;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\WeighmentModeChange;
use App\Models\WeighmentModeConfig;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Platform → Weighment modes (SaaS owner only): the four weighing modes, switched on or off for each company (its
 * defaults) and, where the owner wants, for one mill on its own. Every change is kept with who, when and why.
 * Vehicles already registered keep their mode.
 */
class WeighmentModesPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-vertical';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Weighment modes';

    protected static ?string $title = 'Weighment & laboratory modes';

    protected static ?string $slug = 'platform/weighment-modes';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.pages.platform.weighment-modes';

    public string $search = '';

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isPlatformAdmin();
    }

    /** @return list<array{id: string, name: string, alias: ?string, modes: list<string>, set: bool, mills: list<array{id: string, name: string, own: bool, modes: list<string>, version: string}>}> */
    public function getCompanies(): array
    {
        return app(TenantContext::class)->runAsSystem(function (): array {
            $configs = WeighmentModeConfig::query()->get();
            $byCustomer = $configs->whereNotNull('customer_id')->keyBy('customer_id');
            $byMill = $configs->whereNotNull('entity_id')->keyBy('entity_id');
            $mills = Entity::query()->orderBy('name')->get(['id', 'name', 'code', 'customer_id'])->groupBy('customer_id');
            $term = mb_strtolower(trim($this->search));

            return Customer::query()->orderBy('name')->get(['id', 'name', 'alias'])
                ->filter(fn (Customer $c) => $term === '' || str_contains(mb_strtolower($c->name.' '.$c->alias.' '.($mills[$c->id] ?? collect())->pluck('name')->implode(' ')), $term))
                ->map(fn (Customer $c) => [
                    'id' => $c->id, 'name' => $c->name, 'alias' => $c->alias,
                    'set' => $byCustomer->has($c->id),
                    'modes' => $byCustomer->get($c->id)?->enabled() ?? [],
                    'mills' => ($mills[$c->id] ?? collect())->map(fn (Entity $e) => [
                        'id' => $e->id, 'name' => $e->name.' ('.$e->code.')', 'own' => $byMill->has($e->id),
                        'modes' => WeighModes::effective($e->id)['modes'], 'version' => WeighModes::effective($e->id)['version'],
                    ])->values()->all(),
                ])->values()->all();
        });
    }

    /** @return list<WeighmentModeChange> */
    public function getHistory(): array
    {
        return app(TenantContext::class)->runAsSystem(fn () => WeighmentModeChange::query()->with('changer')->latest('id')->limit(30)->get()->all());
    }

    /** @return array<string, string> customer and mill names for the history */
    public function getNames(): array
    {
        return app(TenantContext::class)->runAsSystem(fn () => Customer::query()->pluck('name', 'id')->all() + Entity::query()->pluck('name', 'id')->all());
    }

    public function companyAction(): Action
    {
        return Action::make('company')->label('Set modes')->icon('heroicon-m-pencil-square')->size('sm')
            ->modalHeading(fn (array $arguments) => 'Company defaults — '.($this->getNames()[$arguments['customer'] ?? ''] ?? ''))
            ->modalDescription('Every mill of this company uses these, unless it has its own setting. Vehicles already registered keep their mode.')
            ->fillForm(fn (array $arguments) => ['modes' => app(TenantContext::class)->runAsSystem(
                fn () => WeighmentModeConfig::query()->where('customer_id', $arguments['customer'] ?? '')->first()?->enabled() ?? [])])
            ->form([
                CheckboxList::make('modes')->label('Switched on')->options(WeighModes::LABELS)->descriptions(WeighModes::HELP),
                Textarea::make('reason')->label('Why (kept in the history)')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, array $arguments): void {
                WeighModes::configure((string) $arguments['customer'], null, array_values((array) ($data['modes'] ?? [])), $data['reason'] ?? null);
                Notification::make()->success()->title('Company modes saved.')->send();
            });
    }

    /** The lab features a company (its defaults) or one mill may use: own tests, deductions, time grace, negotiation. */
    public function labAction(): Action
    {
        return Action::make('lab')->label('Lab features')->icon('heroicon-m-beaker')->size('sm')->color('gray')
            ->modalHeading(fn (array $arguments) => 'Lab features — '.($this->getNames()[$arguments['mill'] ?? $arguments['customer'] ?? ''] ?? ''))
            ->fillForm(function (array $arguments): array {
                $row = app(TenantContext::class)->runAsSystem(fn () => DB::table('lab_feature_configs')
                    ->where(isset($arguments['mill']) ? 'entity_id' : 'customer_id', $arguments['mill'] ?? $arguments['customer'] ?? '')->first());

                return ['follow' => $row === null ? 'default' : 'own',
                    'features' => array_keys(array_filter($row !== null ? array_intersect_key((array) $row, LabSetup::FEATURES) : array_fill_keys(array_keys(LabSetup::FEATURES), true)))];
            })
            ->form(fn (array $arguments) => [
                Radio::make('follow')->label(isset($arguments['mill']) ? 'This mill' : 'This company')->required()->live()
                    ->options(isset($arguments['mill']) ? ['default' => "Follows its company's lab features", 'own' => 'Has its own'] : ['default' => 'Every lab feature allowed', 'own' => 'Only those chosen']),
                CheckboxList::make('features')->label('Allowed')->options(LabSetup::FEATURES)->visible(fn (Get $get) => $get('follow') === 'own'),
            ])
            ->action(function (array $data, array $arguments): void {
                $features = $data['follow'] === 'own' ? array_map(fn (string $f) => in_array($f, (array) ($data['features'] ?? []), true), array_combine(array_keys(LabSetup::FEATURES), array_keys(LabSetup::FEATURES))) : null;
                LabSetup::configure(isset($arguments['mill']) ? null : ($arguments['customer'] ?? null), $arguments['mill'] ?? null, $features);
                Notification::make()->success()->title('Lab features saved.')->send();
            });
    }

    public function millAction(): Action
    {
        return Action::make('mill')->label('Mill setting')->icon('heroicon-m-building-office')->size('sm')->color('gray')
            ->modalHeading(fn (array $arguments) => 'Modes of '.($this->getNames()[$arguments['mill'] ?? ''] ?? 'the mill'))
            ->fillForm(function (array $arguments): array {
                $own = app(TenantContext::class)->runAsSystem(fn () => WeighmentModeConfig::query()->where('entity_id', $arguments['mill'] ?? '')->first());

                return ['follow' => $own === null ? 'company' : 'own', 'modes' => $own?->enabled() ?? WeighModes::enabled($arguments['mill'] ?? null)];
            })
            ->form([
                Radio::make('follow')->label('This mill')->options(['company' => "Follows its company's defaults", 'own' => 'Has its own setting'])->live()->required(),
                CheckboxList::make('modes')->label('Switched on for this mill')->options(WeighModes::LABELS)->descriptions(WeighModes::HELP)
                    ->visible(fn (Get $get) => $get('follow') === 'own'),
                Textarea::make('reason')->label('Why (kept in the history)')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, array $arguments): void {
                WeighModes::configure(null, (string) $arguments['mill'], $data['follow'] === 'own' ? array_values((array) ($data['modes'] ?? [])) : null, $data['reason'] ?? null);
                Notification::make()->success()->title('Mill modes saved.')->send();
            });
    }
}
