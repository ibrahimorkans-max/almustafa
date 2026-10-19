<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Gate\VehicleRegister;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The vehicle register: every vehicle in (paddy received) and out (dispatch) on a day with its
 * vehicle, driver, party, weights, deductions and price; print one vehicle's slip or the whole
 * day's register, and see how the weight and the payable price are worked out.
 */
class VehicleRegisterPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Vehicle register (in / out)';

    protected static ?string $title = 'Vehicle register — in and out';

    protected static ?string $slug = 'vehicle-register';

    protected static ?int $navigationSort = 11;

    protected static string $view = 'filament.pages.vehicle-register';

    /** inbound | outbound | all */
    public string $side = 'inbound';

    public string $day = '';

    public function mount(): void
    {
        $this->day = CarbonImmutable::now('Asia/Karachi')->toDateString();
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->can('viewAny', GateEntry::class);
    }

    public function millChosen(): bool
    {
        return app(TenantContext::class)->entityId() !== null;
    }

    public function date(): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $this->day, 'Asia/Karachi') ?: CarbonImmutable::now('Asia/Karachi')->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now('Asia/Karachi')->startOfDay();
        }
    }

    public function direction(): ?string
    {
        return array_key_exists($this->side, VehicleRegister::SIDES) ? $this->side : null;
    }

    public function updatedSide(): void
    {
        $this->resetTable();
    }

    public function updatedDay(): void
    {
        $this->resetTable();
    }

    public function previousDay(): void
    {
        $this->day = $this->date()->subDay()->toDateString();
        $this->resetTable();
    }

    public function nextDay(): void
    {
        $this->day = $this->date()->addDay()->toDateString();
        $this->resetTable();
    }

    /** @return array<string, array<string, mixed>> totals of each side for the day */
    public function summary(): array
    {
        return array_map(fn (array $s) => $s['totals'] + ['title' => $s['title']], app(VehicleRegister::class)->day($this->date())['sides']);
    }

    public function dayPrintUrl(): string
    {
        return route('filament.admin.vehicle-register.day', ['date' => $this->date()->toDateString()] + ($this->direction() !== null ? ['side' => $this->direction()] : []));
    }

    /** Every vehicle's complete slip of the day in one file, one per page. */
    public function allSlipsUrl(): string
    {
        return route('filament.admin.vehicle-register.day', ['date' => $this->date()->toDateString(), 'slips' => 1] + ($this->direction() !== null ? ['side' => $this->direction()] : []));
    }

    public function downloadExcel(): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->millChosen()) {
            Notification::make()->title('Choose the mill first.')->warning()->send();

            return null;
        }
        $day = $this->date();
        $bytes = app(VehicleRegister::class)->dayExcel($day, $this->direction());
        app(AuditLogger::class)->record('report.vehicle_register.downloaded', null, null, null, ['date' => $day->toDateString(), 'side' => $this->direction()]);

        return response()->streamDownload(fn () => print $bytes, 'vehicle-register-'.$day->toDateString().'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function table(Table $table): Table
    {
        $register = app(VehicleRegister::class);
        $kg = fn (?string $state) => $state === null ? '—' : number_format((float) $state);
        $prices = [];
        $price = function (GateEntry $e) use ($register, &$prices): array {
            return $prices[$e->id] ??= $register->price($e);
        };

        return $table
            ->query(fn (): Builder => $register->query($this->date(), $this->direction()))
            ->defaultSort('arrived_at')
            ->columns([
                TextColumn::make('number')->label('Entry')->searchable()->fontFamily('mono')
                    ->description(fn (GateEntry $e) => $e->direction === 'inbound' ? 'In' : 'Out'),
                TextColumn::make('registration_no')->label('Vehicle')->searchable()->fontFamily('mono')->weight('bold')
                    ->description(fn (GateEntry $e) => $e->vehicle?->vehicle_type),
                TextColumn::make('driver')->label('Driver')
                    ->state(fn (GateEntry $e) => $e->driver->name ?? $e->driver_name ?? '—')
                    ->description(fn (GateEntry $e) => $e->driver?->phone),
                TextColumn::make('party.name')->label('Party')->placeholder('—')->wrap()
                    ->description(fn (GateEntry $e) => trim(($e->product->name ?? '').($e->sauda ? ' · '.$e->sauda->number : ''), ' ·') ?: null),
                TextColumn::make('bag_count')->label('Bags')->alignEnd()->placeholder('—'),
                TextColumn::make('arrived_at')->label('In / out')->dateTime('d M H:i', 'Asia/Karachi')->sortable()
                    ->description(fn (GateEntry $e) => $e->exited_at ? 'Left '.$e->exited_at->timezone('Asia/Karachi')->format('d M H:i') : ($e->status === 'cancelled' ? 'Cancelled' : 'Inside')),
                TextColumn::make('gross_kg')->label('Gross kg')->formatStateUsing($kg)->alignEnd()->placeholder('—'),
                TextColumn::make('tare_kg')->label('Tare kg')->formatStateUsing($kg)->alignEnd()->placeholder('—'),
                TextColumn::make('net_kg')->label('Net kg')->formatStateUsing($kg)->alignEnd()->weight('bold')->placeholder('—'),
                TextColumn::make('deduction_kg')->label('Deduction kg')->formatStateUsing($kg)->alignEnd()->placeholder('—')
                    ->description(fn (GateEntry $e) => $e->rate_cut_paise_per_maund ? 'cut '.Money::paise($e->rate_cut_paise_per_maund)->format().'/md' : null),
                TextColumn::make('payable')->label('Payable / billed')->alignEnd()->weight('bold')
                    ->state(function (GateEntry $e): string {
                        $kg = $e->direction === 'outbound' ? $e->net_kg : $e->payable_kg;

                        return $kg === null ? '—' : Weight::kg($kg)->in('maund', 2).' maund';
                    })
                    ->description(fn (GateEntry $e) => VehicleRegister::kg($e->direction === 'outbound' ? $e->net_kg : $e->payable_kg)),
                TextColumn::make('amount')->label('Rate · amount')->alignEnd()
                    ->state(fn (GateEntry $e) => VehicleRegister::rs($price($e)['net_paise']))
                    ->description(function (GateEntry $e) use ($price): string {
                        $p = $price($e);

                        return ($p['rate_paise'] !== null ? Money::paise($p['rate_paise'])->format().'/md · ' : '').$p['label'];
                    })
                    ->tooltip(fn (GateEntry $e) => implode("\n", $price($e)['steps'])),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => VehicleRegister::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => GateEntryResource::statusColor($state)),
                TextColumn::make('flags')->badge()->color('danger')->separator(',')
                    ->formatStateUsing(fn (string $state) => GateEntryResource::FLAG_LABELS[$state] ?? $state)->placeholder('')->toggleable(),
            ])
            ->recordAction('details')
            ->actions([
                Action::make('details')->label('Details')->icon('heroicon-o-document-magnifying-glass')->button()->color('gray')->size('sm')
                    ->modalHeading(fn (GateEntry $e) => 'Vehicle '.$e->registration_no.' · '.$e->number)
                    ->modalContent(fn (GateEntry $e) => view('filament.pages.vehicle-register-details', ['d' => $register->details($e)]))
                    ->slideOver()->modalWidth('4xl')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->extraModalFooterActions(fn (GateEntry $e): array => [
                        Action::make('printThis')->label('Print this vehicle')->icon('heroicon-o-printer')
                            ->url(route('filament.admin.vehicle-register.vehicle', ['id' => $e->id]), shouldOpenInNewTab: true),
                    ]),
                Action::make('slip')->label('Print')->icon('heroicon-o-printer')->button()->size('sm')
                    ->url(fn (GateEntry $e) => route('filament.admin.vehicle-register.vehicle', ['id' => $e->id]), shouldOpenInNewTab: true),
                ActionGroup::make([
                    Action::make('weighment')->label('Official weighment slip')->icon('heroicon-o-document-text')
                        ->visible(fn (GateEntry $e) => in_array($e->status, ['slip_issued', 'gate_pass_issued', 'exited'], true))
                        ->url(fn (GateEntry $e) => route('filament.admin.vehicle-register.weighment', ['id' => $e->id]), shouldOpenInNewTab: true),
                    Action::make('open')->label('Open gate entry')->icon('heroicon-o-eye')
                        ->url(fn (GateEntry $e) => GateEntryResource::getUrl('view', ['record' => $e])),
                ]),
            ])
            ->emptyStateHeading('No vehicles on this day')
            ->emptyStateDescription('Pick another day above, or record an arrival on the Gate entries screen.')
            ->paginated([25, 50, 100, 'all'])
            ->poll('30s');
    }
}
