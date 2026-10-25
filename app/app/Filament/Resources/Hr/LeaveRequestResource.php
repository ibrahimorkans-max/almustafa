<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\Leave\LeaveService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\LeaveRequestResource\Pages;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Leave';

    protected static ?string $modelLabel = 'leave request';

    protected static ?int $navigationSort = 22;

    public static function getNavigationBadge(): ?string
    {
        $n = LeaveRequest::query()->where('status', LeaveRequest::PENDING)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('employee_id')->label('Employee')->required()->searchable()
                ->options(fn () => Employee::query()->with('person')->where('status', '!=', Employee::EXITED)->orderBy('code')->get()
                    ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} {$e->name()}"])->all()),
            Select::make('leave_type_id')->label('Leave type')->required()
                ->options(fn () => LeaveType::query()->where('status', 'active')->orderBy('code')->pluck('name', 'id')),
            DatePicker::make('from_date')->label('From')->required(),
            DatePicker::make('to_date')->label('To')->required(),
            Select::make('half_day')->options(['none' => 'Full day(s)', 'first' => 'First half', 'second' => 'Second half'])->default('none')->required(),
            Textarea::make('reason')->required()->maxLength(500)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $me = fn (): ?LoginAccount => Filament::auth()->user() instanceof LoginAccount ? Filament::auth()->user() : null;
        $canDecide = fn (LeaveRequest $r): bool => ($me()?->hasPermission('hr.leave.approve') ?? false) && $me()->person_id !== $r->employee->person_id;
        $run = fn (callable $do, string $ok) => function () use ($do, $ok): void {
            try {
                $do();
                Notification::make()->title($ok)->success()->send();
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.person', 'leaveType']))
            ->columns([
                TextColumn::make('employee.code')->label('Employee')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Name'),
                TextColumn::make('leaveType.code')->label('Type')->badge(),
                TextColumn::make('from_date')->label('From')->date('d M Y')->sortable(),
                TextColumn::make('to_date')->label('To')->date('d M Y'),
                TextColumn::make('days')->suffix(fn (LeaveRequest $r) => $r->half_day !== 'none' ? " ({$r->half_day} half)" : ''),
                TextColumn::make('reason')->wrap()->limit(60),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray',
                }),
                TextColumn::make('decision_note')->label('Note')->placeholder('—')->wrap()->limit(60),
            ])
            ->defaultSort('from_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Refused', 'cancelled' => 'Cancelled'])->default('pending'),
                SelectFilter::make('leave_type_id')->label('Type')->options(fn () => LeaveType::query()->pluck('code', 'id')),
            ])
            ->actions([
                Action::make('approve')->color('success')->icon('heroicon-o-check')
                    ->visible(fn (LeaveRequest $r) => $r->status === LeaveRequest::PENDING && $canDecide($r))
                    ->form([Textarea::make('note')->maxLength(500)])
                    ->action(fn (LeaveRequest $r, array $data) => $run(fn () => app(LeaveService::class)->approve($r, $data['note'] ?? null), 'Leave approved.')()),
                Action::make('reject')->label('Refuse')->color('danger')->icon('heroicon-o-x-mark')
                    ->visible(fn (LeaveRequest $r) => $r->status === LeaveRequest::PENDING && $canDecide($r))
                    ->form([Textarea::make('note')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (LeaveRequest $r, array $data) => $run(fn () => app(LeaveService::class)->reject($r, $data['note']), 'Leave refused.')()),
                Action::make('cancel')->color('gray')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (LeaveRequest $r) => $r->status === LeaveRequest::APPROVED && $canDecide($r))
                    ->modalDescription('The days go back to the balance (recorded as a reversal).')
                    ->form([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (LeaveRequest $r, array $data) => $run(fn () => app(LeaveService::class)->cancel($r, $data['reason']), 'Leave cancelled; days returned.')()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeaveRequests::route('/'),
            'create' => Pages\CreateLeaveRequest::route('/create'),
        ];
    }
}
