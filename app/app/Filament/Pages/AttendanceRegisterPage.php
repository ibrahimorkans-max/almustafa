<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Hr\Attendance\AttendanceRegister;
use App\Filament\Resources\Hr\HrScope;
use App\Models\Hr\Department;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/** Month × employees: P / ½P / A / L / ½L / HO / WO / MO, with totals. */
class AttendanceRegisterPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $title = 'Attendance register';

    protected static ?string $slug = 'hr/attendance-register';

    protected static ?int $navigationSort = 16;

    protected static string $view = 'filament.pages.attendance-register';

    public string $month = '';

    public ?string $department = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('hr.employee.view');
    }

    public function mount(): void
    {
        $this->month = now('Asia/Karachi')->format('Y-m');
    }

    /** @return array<string, string> */
    public function departments(): array
    {
        return Department::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array{month: string, dates: list<string>, rows: list<array<string, mixed>>} */
    public function register(): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now('Asia/Karachi')->format('Y-m');

        return app(AttendanceRegister::class)->month($month, $this->department ?: null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Export CSV')->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => response()->streamDownload(
                    fn () => print app(AttendanceRegister::class)->csv($this->month, $this->department ?: null),
                    "attendance-register-{$this->month}.csv", ['Content-Type' => 'text/csv'],
                )),
        ];
    }
}
