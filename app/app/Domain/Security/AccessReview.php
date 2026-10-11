<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Audit\AuditLogger;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;

/**
 * Periodic access review for administrators: every login account of the working entity with its
 * role, permissions, sign-in state and last sign-in, and the accounts that need a decision
 * (dormant, never used, setup pending, locked, duress alert). Figures come straight from the
 * accounts; nothing is estimated.
 */
final class AccessReview
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function dormantDays(): int
    {
        return (int) config('ricemill.security.dormant_days', 30);
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $cutoff = now()->subDays(self::dormantDays());

        return LoginAccount::query()->with(['role', 'person', 'entity'])->orderBy('username')->get()
            ->map(function (LoginAccount $a) use ($cutoff): array {
                $permissions = $a->permissionCodes();
                sort($permissions);

                return [
                    'id' => $a->id,
                    'username' => $a->username,
                    'person' => $a->person->full_name ?? '',
                    'entity' => $a->entity->code ?? '',
                    'role' => $a->role->name ?? '',
                    'status' => $a->status,
                    'two_factor' => $a->hasMfa() ? 'yes' : ($a->mfaRequired() ? 'required, not set up' : 'no'),
                    'last_login' => $a->last_login_at ? CarbonImmutable::parse($a->last_login_at)->timezone('Asia/Karachi')->format('d M Y H:i') : 'never',
                    'flags' => implode(', ', array_filter([
                        $a->isActive() && $a->last_login_at === null ? 'never signed in' : null,
                        $a->isActive() && $a->last_login_at !== null && $a->last_login_at->lt($cutoff) ? 'dormant' : null,
                        $a->hasPendingSecuritySetup() ? 'setup pending' : null,
                        $a->isLocked() ? ($a->needs_admin_unlock ? 'locked (admin unlock)' : 'locked') : null,
                        $a->duress_flagged_at ? 'duress alert' : null,
                    ])),
                    'permission_count' => count($permissions),
                    'permissions' => implode(' ', $permissions),
                ];
            })->values()->all();
    }

    public function csv(): string
    {
        $rows = $this->rows();
        $columns = ['username', 'person', 'entity', 'role', 'status', 'two_factor', 'last_login', 'flags', 'permission_count', 'permissions'];
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot build the file.');
        fputcsv($out, $columns, escape: '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($c) => self::cell($row[$c]), $columns), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit->record('report.exported', null, null, null, ['report' => 'access_review', 'rows' => count($rows)]);

        return $csv;
    }

    private static function cell(mixed $v): mixed
    {
        // Spreadsheet formula injection: text starting with = + - @ is defused.
        return is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
    }
}
