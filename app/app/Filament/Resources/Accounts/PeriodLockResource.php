<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Domain\Approvals\Actions\PeriodUnlock;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Filament\Resources\Accounts\PeriodLockResource\Pages;
use App\Filament\Support\ApprovalNotice;
use App\Models\Accounts\PeriodLock;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class PeriodLockResource extends Resource
{
    protected static ?string $model = PeriodLock::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Period locks';

    protected static ?string $slug = 'period-locks';

    protected static ?int $navigationSort = 80;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period')->sortable(),
                TextColumn::make('locked')->badge()->formatStateUsing(fn (bool $state) => $state ? 'Locked' : 'Reopened')
                    ->color(fn (bool $state) => $state ? 'success' : 'warning'),
                TextColumn::make('locked_at')->label('Locked')->dateTime('d M Y H:i'),
            ])
            ->defaultSort('period', 'desc')
            ->actions([
                Action::make('unlock')->label('Request reopening')->color('danger')
                    ->visible(fn (PeriodLock $r) => $r->locked && (Filament::auth()->user()?->can('period.lock') ?? false))
                    ->form([Textarea::make('reason')->required()->minLength(10)->maxLength(1000)])
                    ->action(function (PeriodLock $record, array $data): void {
                        try {
                            $request = app(ApprovalService::class)->submit(PeriodUnlock::KEY, ['period' => $record->period, 'reason' => $data['reason']], "Reopen books for {$record->period}", $data['reason']);
                        } catch (ApprovalDenied|ValidationException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        ApprovalNotice::send($request, 'Sent for approval', link: false);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPeriodLocks::route('/')];
    }
}
