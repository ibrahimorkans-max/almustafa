<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeResource\RelationManagers;

use App\Domain\Hr\Leave\LeaveService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveLedgerEntry;
use App\Models\Hr\LeaveType;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Leave balances as the sum of ledger movements; every movement is listed. */
class LeaveLedgerRelationManager extends RelationManager
{
    protected static string $relationship = 'leaveLedger';

    protected static ?string $title = 'Leave';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function balancesText(): string
    {
        /** @var Employee $employee */
        $employee = $this->getOwnerRecord();
        $year = (int) now('Asia/Kolkata')->year;
        $parts = LeaveType::query()->where('paid', true)->orderBy('code')->get()
            ->map(fn (LeaveType $t) => "{$t->code} ".app(LeaveService::class)->balance($employee, $t, $year));

        return "Balances {$year}: ".($parts->implode(' · ') ?: '—');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => $this->balancesText())
            ->modifyQueryUsing(fn (Builder $query) => $query->with('leaveType'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i', 'Asia/Kolkata'),
                TextColumn::make('year'),
                TextColumn::make('leaveType.code')->label('Type')->badge(),
                TextColumn::make('kind'),
                TextColumn::make('days')->alignEnd()->color(fn (LeaveLedgerEntry $r) => (float) $r->days < 0 ? 'danger' : 'success'),
                TextColumn::make('note')->wrap()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('year')->options(fn () => LeaveLedgerEntry::query()->distinct()->orderByDesc('year')->pluck('year', 'year')->all())])
            ->headerActions([
                Action::make('adjust')->label('Adjust balance')->icon('heroicon-o-plus-circle')
                    ->visible(function (): bool {
                        $user = Filament::auth()->user();

                        return $user instanceof LoginAccount && $user->hasPermission('hr.employee.edit');
                    })
                    ->form([
                        Select::make('leave_type_id')->label('Leave type')->required()
                            ->options(fn () => LeaveType::query()->where('paid', true)->orderBy('code')->pluck('name', 'id')),
                        TextInput::make('year')->required()->integer()->default((int) now('Asia/Kolkata')->year),
                        Select::make('kind')->options(['opening' => 'Opening balance', 'accrual' => 'Accrual (earned)', 'adjustment' => 'Correction (+ or −)'])->required(),
                        TextInput::make('days')->required()->regex('/^-?\d{1,3}(\.[05])?$/')->helperText('Whole or half days, e.g. 7 or 1.5'),
                        Textarea::make('note')->required()->maxLength(500),
                    ])
                    ->action(function (array $data): void {
                        /** @var Employee $employee */
                        $employee = $this->getOwnerRecord();
                        try {
                            app(LeaveService::class)->adjust($employee, LeaveType::query()->findOrFail($data['leave_type_id']),
                                (int) $data['year'], $data['kind'], (string) $data['days'], (string) $data['note']);
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Balance recorded.')->success()->send();
                    }),
            ]);
    }
}
