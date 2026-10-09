<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\Attendance\AttendanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\EmployeeDeviceResource\Pages;
use App\Models\Hr\EmployeeDevice;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Phones employees asked to use for attendance: one approved per employee. */
class EmployeeDeviceResource extends Resource
{
    protected static ?string $model = EmployeeDevice::class;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Attendance devices';

    protected static ?string $modelLabel = 'attendance device';

    protected static ?int $navigationSort = 60;

    public static function getNavigationBadge(): ?string
    {
        $n = EmployeeDevice::query()->where('status', EmployeeDevice::PENDING)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $canDecide = function (EmployeeDevice $r): bool {
            $user = Filament::auth()->user();

            return $r->status === EmployeeDevice::PENDING && $user instanceof LoginAccount && $user->hasPermission('hr.employee.edit');
        };
        $decide = fn (bool $approve) => function (EmployeeDevice $r, array $data) use ($approve): void {
            try {
                app(AttendanceService::class)->decideDevice($r, $approve, $data['note'] ?? null);
                Notification::make()->title($approve ? 'Device approved.' : 'Device rejected.')->success()->send();
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };

        return $table
            ->columns([
                TextColumn::make('employee.code')->label('Employee')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Name'),
                TextColumn::make('device.name')->label('Phone')->placeholder('—')->limit(40),
                TextColumn::make('device.platform')->label('Platform'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'pending' => 'warning', default => 'gray',
                }),
                TextColumn::make('requested_at')->dateTime('d M Y H:i'),
                TextColumn::make('decision_note')->placeholder('—')->wrap(),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([SelectFilter::make('status')->options([
                'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'revoked' => 'Replaced',
            ])->default('pending')])
            ->actions([
                Action::make('approve')->color('success')->icon('heroicon-o-check')->visible($canDecide)
                    ->modalDescription('Any previously approved phone of this employee stops being accepted for attendance.')
                    ->form([Textarea::make('note')->maxLength(500)])->action($decide(true)),
                Action::make('reject')->color('danger')->icon('heroicon-o-x-mark')->visible($canDecide)
                    ->form([Textarea::make('note')->label('Reason')->required()->maxLength(500)])->action($decide(false)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEmployeeDevices::route('/')];
    }
}
