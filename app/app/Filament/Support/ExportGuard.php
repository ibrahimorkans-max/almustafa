<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Audit\AuditLogger;
use App\Models\LoginAccount;
use Filament\Facades\Filament;

/**
 * Downloading data out of the system (reports and lists as Excel, CSV or PDF) needs 'report.export'.
 * Without it a person still sees the reports on screen. Buttons are hidden (Filament then refuses the
 * action too); methods called directly check again. Printing one invoice, slip or voucher is not an
 * export and is not affected.
 */
final class ExportGuard
{
    public const PERMISSION = 'report.export';

    /**
     * @param  string|null  $ownRight  a module's own reports (HR registers) may also be downloaded with that module's right
     */
    public static function allowed(?string $ownRight = null): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ($me->hasPermission(self::PERMISSION) || ($ownRight !== null && $me->hasPermission($ownRight)));
    }

    /** Refuses (403, recorded) when the person may not download. */
    public static function assert(): void
    {
        if (! self::allowed()) {
            app(AuditLogger::class)->record('report.export.refused', null, null, null, ['url' => request()->path()]);
            abort(403, 'You may see this report on screen, but not download it.');
        }
    }
}
