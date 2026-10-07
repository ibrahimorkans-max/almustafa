<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Alerts\AlertService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\AlertResource\Pages;
use App\Models\Alert;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Things someone must look at. Acknowledged with a note, never deleted. */
class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Alerts';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $open = Alert::query()->where('status', 'open')->count();

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
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('severity')->badge()->color(fn (string $state) => match ($state) {
                    'critical' => 'danger', 'warning' => 'warning', default => 'gray',
                }),
                TextColumn::make('title')->wrap()->description(fn (Alert $record) => $record->body),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'danger' : 'success'),
                TextColumn::make('acknowledgement_note')->label('Finding')->wrap()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'acknowledged' => 'Acknowledged'])->default('open'),
                SelectFilter::make('type')->options([
                    'outturn_drift' => 'Outturn drift', 'unaccounted_loss' => 'Unaccounted loss', 'shrinkage' => 'Shrinkage',
                ]),
            ])
            ->actions([
                Action::make('acknowledge')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Alert $record) => $record->isOpen())
                    ->form([Textarea::make('note')->label('What was found / done')->required()->minLength(5)->maxLength(2000)])
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
