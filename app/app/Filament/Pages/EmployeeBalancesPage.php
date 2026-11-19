<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reports\Balances;
use App\Filament\Concerns\TranslatesLabels;
use App\Filament\Resources\Hr\HrScope;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * HR → Employee balances: for a month, every employee's salary, what is paid (salary vouchers, finalized payroll),
 * what is still due, and the advances / loans outstanding.
 */
class EmployeeBalancesPage extends Page
{
    use TranslatesLabels;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Employee balances';

    protected static ?string $title = 'Employee balances';

    protected static ?string $slug = 'hr/employee-balances';

    protected static ?int $navigationSort = 36;

    protected static string $view = 'filament.pages.employee-balances';

    #[Url]
    public string $month = '';

    #[Url]
    public string $search = '';

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ($me->hasPermission('hr.payroll.run') || $me->hasPermission('hr.salary.view'));
    }

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now('Asia/Karachi')->format('Y-m');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) ? $this->month : now('Asia/Karachi')->format('Y-m');

        return app(Balances::class)->employees($month, trim($this->search) ?: null);
    }
}
