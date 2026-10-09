<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeResource\RelationManagers;

use App\Domain\Hr\EmployeeService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\ClearanceItem;
use App\Models\Hr\Employee;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ClearanceItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'clearanceItems';

    protected static ?string $title = 'Exit clearance';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Employee
            && ($ownerRecord->notice_given_on !== null || $ownerRecord->exited_on !== null || $ownerRecord->clearanceItems()->exists());
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item'),
                TextColumn::make('done_at')->label('Cleared')->dateTime('d M Y H:i')->placeholder('open'),
                TextColumn::make('done_by')->label('By')->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? LoginAccount::query()->whereKey($state)->value('username') : null),
                TextColumn::make('note')->wrap()->placeholder('—'),
            ])
            ->defaultSort('created_at')
            ->actions([
                Action::make('clear')->label('Mark cleared')->icon('heroicon-o-check')
                    ->visible(function (ClearanceItem $r): bool {
                        $user = Filament::auth()->user();

                        return $r->done_at === null && $user instanceof LoginAccount && $user->hasPermission('hr.employee.edit');
                    })
                    ->form([Textarea::make('note')->maxLength(500)])
                    ->action(function (ClearanceItem $r, array $data): void {
                        try {
                            app(EmployeeService::class)->completeClearance($r, $data['note'] ?? null);
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
