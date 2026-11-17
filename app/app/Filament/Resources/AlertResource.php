<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Alerts\AlertService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\AlertResource\Pages;
use App\Models\Alert;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Things someone must look at. Acknowledged with a note, never deleted. */
class AlertResource extends Resource
{
    use \App\Filament\Concerns\TranslatesResourceLabels;

    protected static ?string $model = Alert::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Alerts';

    protected static ?int $navigationSort = 60;

    /**
     * Each person sees only the alerts raised for permissions they hold.
     *
     * @return Builder<Alert>
     */
    public static function getEloquentQuery(): Builder
    {
        $user = Filament::auth()->user();
        $query = Alert::query();

        return $user instanceof LoginAccount ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = static::getEloquentQuery()->where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('severity')->badge()->color(fn (string $state) => match ($state) {
                    'critical' => 'danger', 'warning' => 'warning', default => 'gray',
                }),
                TextColumn::make('title')->wrap()->description(fn (Alert $record) => $record->body),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'danger' : 'success'),
                TextColumn::make('acknowledgement_note')->label(__('Finding'))->wrap()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['open' => __('Open'), 'acknowledged' => __('Acknowledged')])->default('open'),
                SelectFilter::make('type')->options([
                    'outturn_drift' => __('Outturn drift'), 'unaccounted_loss' => __('Unaccounted loss'), 'shrinkage' => __('Shrinkage'),
                    'abnormal_overtime' => __('Abnormal overtime'), 'payroll_variance' => __('Payroll change'), 'minimum_wage_shortfall' => __('Below minimum wage'),
                    'failed_delivery' => __('Failed document delivery'),
                ]),
            ])
            ->actions([
                Action::make('acknowledge')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Alert $record) => $record->isOpen())
                    ->form([Textarea::make('note')->label(__('What was found / done'))->required()->minLength(5)->maxLength(2000)])
                    ->action(function (Alert $record, array $data): void {
                        try {
                            app(AlertService::class)->acknowledge($record, $data['note']);
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAlerts::route('/')];
    }
}
