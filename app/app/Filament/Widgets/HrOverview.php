<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Reports\HrDashboardMetrics;
use App\Filament\Resources\Hr\AdvanceResource;
use App\Filament\Resources\Hr\AttendanceLogResource;
use App\Filament\Resources\Hr\GeneratedDocumentResource;
use App\Filament\Resources\Hr\LeaveRequestResource;
use App\Filament\Resources\Hr\PayrollRunResource;
use App\Filament\Support\Units;
use App\Models\Hr\PayrollRun;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Today's attendance and what HR has waiting — for anyone who sees employees. */
class HrOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '120s';

    protected ?string $heading = 'People';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('hr.employee.view');
    }

    protected function getStats(): array
    {
        $m = app(HrDashboardMetrics::class);
        $t = $m->today();
        $hr = $m->hr();
        $user = Filament::auth()->user();
        $sees = fn (string $p) => $user instanceof LoginAccount && $user->hasPermission($p);

        $stats = [
            Stat::make('Present today', "{$t['present']} of {$t['headcount']}")
                ->description("{$t['late']} late · {$t['on_leave']} on leave · {$t['absent']} absent".($t['off'] > 0 ? " · {$t['off']} off" : ''))
                ->color($t['absent'] > 0 ? 'warning' : 'success'),
            Stat::make('Punches to review', (string) $hr['flagged_punches'])->color($hr['flagged_punches'] > 0 ? 'warning' : 'gray')
                ->url(AttendanceLogResource::getUrl('index')),
            Stat::make('Leave', "{$hr['leave_pending']} to decide")->description("{$hr['leave_next_7_days']} on leave in the next 7 days")
                ->url(LeaveRequestResource::getUrl('index')),
        ];
        if ($sees('hr.salary.view')) {
            $stats[] = Stat::make('Payroll', $hr['payroll_month'] ?? 'none yet')
                ->description($hr['payroll_status'] !== null ? (PayrollRun::STATUSES[$hr['payroll_status']] ?? $hr['payroll_status']) : 'Start the month under Payroll')
                ->url(PayrollRunResource::getUrl('index'));
            $stats[] = Stat::make('Advances to recover', Units::paiseToRupees($hr['advances_outstanding']))->url(AdvanceResource::getUrl('index'));
        }
        if ($sees('hr.documents.generate')) {
            $stats[] = Stat::make('Payslips not delivered', (string) $hr['undelivered_payslips'])
                ->description('Last payroll: never shared, opened or downloaded')->color($hr['undelivered_payslips'] > 0 ? 'warning' : 'gray')
                ->url(GeneratedDocumentResource::getUrl('index'));
        }

        return $stats;
    }
}
