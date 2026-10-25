<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\Payroll\PayAdjustmentService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\PayAdjustmentResource\Pages;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\Units;
use App\Models\Hr\PayAdjustment;
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

/** Bonuses, arrears, fines and other one-off deductions: requested with a reason, approved by a second person. */
class PayAdjustmentResource extends Resource
{
    protected static ?string $model = PayAdjustment::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Bonuses and deductions';

    protected static ?string $modelLabel = 'pay adjustment';

    protected static ?string $slug = 'hr/pay-adjustments';

    protected static ?int $navigationSort = 41;

    public static function getNavigationBadge(): ?string
    {
        $n = PayAdjustment::query()->where('status', PayAdjustment::REQUESTED)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function allows(string $permission): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission($permission);
    }

    public static function table(Table $table): Table
    {
        $run = fn (callable $do, string $ok) => function (PayAdjustment $record, array $data = []) use ($do, $ok): void {
            try {
                $do($record, $data);
                Notification::make()->title($ok)->success()->send();
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };
        $svc = fn () => app(PayAdjustmentService::class);
        $open = fn (PayAdjustment $r) => $r->status === PayAdjustment::REQUESTED && self::allows('hr.payroll.approve');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee.person'))
            ->columns([
                TextColumn::make('month')->sortable(),
                TextColumn::make('employee.code')->label('Employee')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Name'),
                TextColumn::make('type')->formatStateUsing(fn (string $state) => PayAdjustment::TYPES[$state] ?? $state),
                TextColumn::make('name')->label('Item'),
                TextColumn::make('amount_paise')->label('Amount')->alignEnd()->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state)),
                TextColumn::make('reason')->limit(40)->tooltip(fn (PayAdjustment $r) => $r->reason),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    PayAdjustment::REQUESTED => 'warning', PayAdjustment::APPROVED => 'success', default => 'danger',
                }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([PayAdjustment::REQUESTED => 'Waiting for approval', PayAdjustment::APPROVED => 'Approved', PayAdjustment::REJECTED => 'Refused']),
                SelectFilter::make('type')->options(PayAdjustment::TYPES),
            ])
            ->actions([
                Action::make('slip')->label('Slip')->icon('heroicon-o-printer')->color('gray')
                    ->visible(fn (PayAdjustment $r) => $r->status === PayAdjustment::APPROVED && in_array($r->type, ['bonus', 'earning'], true) && DocumentActions::allowed())
                    ->form(DocumentActions::form('bonus_slip'))
                    ->action(fn (PayAdjustment $record, array $data) => DocumentActions::run('bonus_slip', $record->id, $data)),
                Action::make('approve')->icon('heroicon-o-check')->color('success')->visible($open)
                    ->form([Textarea::make('note')->maxLength(500)])
                    ->action($run(fn (PayAdjustment $r, array $d) => $svc()->approve($r, $d['note'] ?? null), 'Approved.')),
                Action::make('approveBatch')->label('Approve whole batch')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (PayAdjustment $r) => $open($r) && $r->batch_id !== null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (PayAdjustment $r) => PayAdjustment::query()->where('batch_id', $r->batch_id)->where('status', PayAdjustment::REQUESTED)->count().' items of this batch are waiting.')
                    ->form([Textarea::make('note')->maxLength(500)])
                    ->action($run(fn (PayAdjustment $r, array $d) => $svc()->approveBatch((string) $r->batch_id, $d['note'] ?? null), 'Batch approved.')),
                Action::make('reject')->label('Refuse')->icon('heroicon-o-x-mark')->color('danger')->visible($open)
                    ->form([Textarea::make('note')->label('Reason')->required()->minLength(5)->maxLength(500)])
                    ->action($run(fn (PayAdjustment $r, array $d) => $svc()->reject($r, $d['note']), 'Refused.')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayAdjustments::route('/')];
    }
}
