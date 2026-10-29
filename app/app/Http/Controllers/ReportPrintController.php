<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reports\ReportCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /admin/reports/print?r[]=pl&r[]=trial_balance&from=2026-10-01&to=2026-10-31[&format=xlsx][&department=…]
 * One or many reports as one PDF (opened in the browser, ready to print) or one Excel workbook.
 * Reports the person may not see are refused; the mill is the working mill.
 */
final class ReportPrintController
{
    public function __invoke(Request $request, ReportCatalog $catalog, AuditLogger $audit, TenantContext $tenant): Response
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof LoginAccount, 403);
        abort_if($tenant->entityId() === null, 409, 'Choose the mill (working entity) first.');

        $keys = array_values(array_unique(array_filter((array) $request->query('r', []), 'is_string')));
        abort_if($keys === [] || count($keys) > count(ReportCatalog::REPORTS), 404);
        foreach ($keys as $key) {
            abort_unless(ReportCatalog::allowed($user, $key), 403);
        }

        $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();
        $from = self::date($request->query('from')) ?? $today->startOfMonth();
        $to = self::date($request->query('to')) ?? $today;
        abort_if($to->lessThan($from), 422, 'The end date is before the start date.');
        abort_if($from->diffInDays($to) > 400, 422, 'Choose at most about a year.');

        $department = $request->query('department');
        $options = is_string($department) && $department !== '' ? ['department' => $department] : [];
        $excel = $request->query('format') === 'xlsx';
        $name = (count($keys) === 1 ? str_replace('_', '-', $keys[0]) : 'all-reports').'-'.$from->toDateString().'-'.$to->toDateString();

        $audit->record('report.printed', null, null, null, ['reports' => $keys, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'format' => $excel ? 'xlsx' : 'pdf']);

        if ($excel) {
            return response($catalog->excel($keys, $from, $to, $options), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$name.'.xlsx"',
            ]);
        }

        return response($catalog->pdf($keys, $from, $to, $options), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'.pdf"', 'X-Print-Frame' => '1']);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Karachi') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The link for a report (or several), used by the report screens.
     *
     * @param  list<string>  $keys
     * @param  array<string, string>  $extra
     */
    public static function url(array $keys, ?string $from, ?string $to, array $extra = []): string
    {
        return route('filament.admin.reports.print', ['r' => $keys, 'from' => $from, 'to' => $to] + $extra);
    }
}
