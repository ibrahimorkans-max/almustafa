<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Gate\GatePolicy;
use App\Domain\Gate\LabSetup;
use App\Domain\Gate\WeighModes;
use App\Domain\Tenancy\TenantContext;
use App\Models\Gate\GateEntry;
use App\Models\Gate\LabTest;
use App\Models\LoginAccount;
use Filament\Actions\Action as PageAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Gate → Lab tests to add: every paddy vehicle waiting for its lab result, in token order. With the mill's policy
 * "weigh before the lab" (GatePolicy) a vehicle is weighed loaded as soon as it arrives and stays here until the lab
 * decides; each one opens its own page (LabTestEntryPage) to add the lab test.
 */
class LabQueuePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Lab tests to add';

    protected static ?string $title = 'Lab tests to add';

    protected static ?string $slug = 'gate/lab-tests';

    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.pages.lab-queue';

    public static function canAccess(): bool
    {
        return self::can('lab.test') || self::can('lab.decide');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = self::query()->count();

        return $n > 0 ? (string) $n : null;
    }

    /** @return Builder<GateEntry> paddy lots without a lab decision yet (weighed loaded or not), still open */
    public static function query(): Builder
    {
        return self::waitingFilter(GateEntry::query()->with(['party', 'product'])
            ->where('direction', 'inbound')->where('purpose', '!=', 'other'));
    }

    /**
     * Lots that wait for the lab: just arrived, or weighed loaded in a weigh-first mode (1, 3); never a lot weighed
     * without a lab (mode 2). Each lot by its own mode (lots from before the modes: the mill's earlier policy).
     *
     * @param  Builder<GateEntry>  $query
     * @return Builder<GateEntry>
     */
    public static function waitingFilter(Builder $query): Builder
    {
        $legacyWeighFirst = GatePolicy::weighBeforeLab(app(TenantContext::class)->entityId());

        return $query->where(fn (Builder $q) => $q->whereNull('weigh_mode')->orWhere('weigh_mode', '!=', WeighModes::NO_LAB))
            ->where(fn (Builder $q) => $q->where('status', 'arrived')->orWhere(fn (Builder $q) => $q->where('status', 'gross_weighed')
                ->where(fn (Builder $q) => $q->whereIn('weigh_mode', [WeighModes::LAB_BEFORE_SLIP, WeighModes::WEIGH_FIRST_LAB_LATER])
                    ->when($legacyWeighFirst, fn (Builder $q) => $q->orWhereNull('weigh_mode')))));
    }

    public function weighFirst(): bool
    {
        return GatePolicy::weighBeforeLab(app(TenantContext::class)->entityId());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::query())
            ->defaultSort(fn (Builder $q) => $q->orderByRaw('token_serial IS NULL')->orderBy('token_serial')->orderBy('arrived_at'))
            ->poll('15s')
            ->columns([
                TextColumn::make('token')->label('Token')->state(fn (GateEntry $e) => $e->token_serial !== null || $e->token_no !== null ? $e->tokenLabel() : '—'),
                TextColumn::make('registration_no')->label('Vehicle')->state(fn (GateEntry $e) => $e->vehicleLabel())->searchable()->weight('bold'),
                TextColumn::make('party')->label('Party')->state(fn (GateEntry $e) => $e->partyLabel() ?? '—'),
                TextColumn::make('item')->label('Item')->state(fn (GateEntry $e) => $e->itemLabel()),
                TextColumn::make('bag_count')->label('Bags')->placeholder('—'),
                TextColumn::make('gross_kg')->label('Loaded weight')->placeholder('not weighed yet')
                    ->formatStateUsing(fn ($state) => $state === null ? null : number_format((float) $state, 0).' kg'),
                TextColumn::make('arrived_at')->label('Arrived')->since()->tooltip(fn (GateEntry $e) => $e->arrived_at?->timezone('Asia/Karachi')->format('d M Y, h:i A')),
                TextColumn::make('lab')->label('Lab')->badge()
                    ->state(fn (GateEntry $e) => LabTest::query()->where('gate_entry_id', $e->id)->exists() ? 'Result saved, waiting for decision' : 'Lab test to add')
                    ->color(fn (string $state) => str_starts_with($state, 'Result') ? 'warning' : 'gray'),
            ])
            ->recordUrl(fn (GateEntry $e) => LabTestEntryPage::getUrl(['entry' => $e->id]))
            ->actions([
                Action::make('lab')->label(fn (GateEntry $e) => LabTest::query()->where('gate_entry_id', $e->id)->exists() ? 'Open lab test' : 'Add lab test')
                    ->icon('heroicon-o-beaker')->button()->url(fn (GateEntry $e) => LabTestEntryPage::getUrl(['entry' => $e->id])),
            ])
            ->emptyStateHeading('No vehicle is waiting for its lab test.')
            ->emptyStateDescription('Paddy vehicles show here from their arrival until the lab accepts or rejects them.')
            ->emptyStateIcon('heroicon-o-beaker');
    }

    protected function getHeaderActions(): array
    {
        return [
            // How long the lab has for a lot, what happens to a late result, and the alert when the time runs out.
            PageAction::make('timeRule')->label('Lab time rule')->icon('heroicon-o-clock')->color('gray')
                ->visible(fn () => self::can('lab.limits') && LabSetup::allows(app(TenantContext::class)->entityId(), 'time_grace'))
                ->fillForm(fn () => (array) (DB::table('lab_time_rules')->where('entity_id', app(TenantContext::class)->entityId())->first()
                    ?? ['minutes' => 60, 'starts_at_event' => 'arrived', 'ends_at_event' => 'lab_result', 'alert_on_expiry' => true, 'late_rule' => 'approval', 'active' => true]))
                ->form([
                    Toggle::make('active')->label('Use a time rule'),
                    TextInput::make('minutes')->label('Minutes the lab has')->integer()->minValue(5)->maxValue(10080)->required(),
                    Select::make('starts_at_event')->label('Counted from')->options(LabSetup::STARTS)->required(),
                    Select::make('ends_at_event')->label('Until')->options(LabSetup::ENDS)->required(),
                    Select::make('late_rule')->label('A late result')->options(LabSetup::LATE_RULES)->required(),
                    Toggle::make('alert_on_expiry')->label('Alert the lab supervisors when the time runs out'),
                ])
                ->action(function (array $data): void {
                    $entity = (string) app(TenantContext::class)->entityId();
                    $values = ['minutes' => (int) $data['minutes'], 'starts_at_event' => $data['starts_at_event'], 'ends_at_event' => $data['ends_at_event'],
                        'late_rule' => $data['late_rule'], 'alert_on_expiry' => (bool) $data['alert_on_expiry'], 'active' => (bool) $data['active'],
                        'updated_by' => Filament::auth()->id(), 'updated_at' => now()];
                    $q = DB::table('lab_time_rules')->where('entity_id', $entity);
                    $q->exists() ? $q->update($values) : DB::table('lab_time_rules')->insert($values + ['id' => (string) Str::uuid(), 'entity_id' => $entity, 'created_at' => now()]);
                    app(AuditLogger::class)->record('lab.time_rule', null, null, $values);
                    Notification::make()->success()->title('Lab time rule saved.')->send();
                }),
            // The modes the SaaS owner switched on for this mill: seen here, never changed here; the mill picks its default.
            PageAction::make('modes')->label('Weighing modes')->icon('heroicon-o-adjustments-vertical')->color('gray')
                ->visible(fn () => WeighModes::effective(app(TenantContext::class)->entityId())['level'] !== 'default')
                ->modalDescription('The SaaS owner decides which modes this mill may use. A vehicle keeps the mode it was registered in.')
                ->fillForm(fn () => ['default' => WeighModes::defaultMode(app(TenantContext::class)->entityId())])
                ->form([
                    Placeholder::make('on')->label('Switched on for this mill')
                        ->content(fn () => new HtmlString(collect(WeighModes::enabled(app(TenantContext::class)->entityId()))
                            ->map(fn (string $m) => '<div class="mb-1"><b>'.e(WeighModes::LABELS[$m]).'</b><br><span class="text-xs text-gray-500">'.e(WeighModes::HELP[$m]).'</span></div>')->implode('') ?: 'None — ask the SaaS owner.')),
                    Radio::make('default')->label('New paddy vehicles start in')->options(fn () => WeighModes::options(app(TenantContext::class)->entityId()))
                        ->disabled(fn () => ! self::can('settings.manage'))->required(),
                ])
                ->modalSubmitAction(fn ($action) => self::can('settings.manage') ? $action : false)
                ->action(function (array $data): void {
                    WeighModes::chooseDefault((string) app(TenantContext::class)->entityId(), (string) $data['default']);
                    Notification::make()->success()->title('Default weighing mode saved.')->send();
                }),
            PageAction::make('policy')->label('Weighing policy')->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->visible(fn () => self::can('settings.manage') && WeighModes::effective(app(TenantContext::class)->entityId())['level'] === 'default')
                ->modalDescription('When is a paddy vehicle weighed? Unloading always waits for the lab, so rejected paddy never goes into a godown.')
                ->fillForm(fn () => ['policy' => $this->weighFirst() ? 'weigh_first' : 'lab_first'])
                ->form([
                    Radio::make('policy')->label('Weighing')->required()->options([
                        'weigh_first' => 'Weigh as soon as it arrives; add the lab test on its own page before unloading',
                        'lab_first' => 'The lab accepts the lot first, then it is weighed',
                    ]),
                ])
                ->action(function (array $data): void {
                    GatePolicy::set($data['policy'] === 'weigh_first', (string) app(TenantContext::class)->entityId());
                    Notification::make()->success()->title($data['policy'] === 'weigh_first' ? 'Vehicles are weighed before the lab.' : 'The lab accepts each lot before it is weighed.')->send();
                }),
        ];
    }

    private static function can(string $permission): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission($permission);
    }
}
