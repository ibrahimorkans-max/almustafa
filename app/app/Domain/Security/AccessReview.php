<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Audit\AuditLogger;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Periodic access review for administrators: every login account of the working entity with its
 * role, permissions, sign-in state and last sign-in, and the accounts that need a decision
 * (dormant, never used, setup pending, locked, duress alert), and where each person is: the last
 * exact position their browser shared (area, city, region, country, ±metres), else the place of the IP
 * of their last sign-in, and whether they are online now. Figures come straight from the accounts and
 * sessions; nothing is estimated.
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
        $places = $this->lastPlaces();

        return LoginAccount::query()->with(['role', 'person', 'entity'])->orderBy('username')->get()
            ->map(function (LoginAccount $a) use ($cutoff, $places): array {
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
                ] + ($places[$a->id] ?? self::noPlace());
            })->values()->all();
    }

    public function csv(): string
    {
        $rows = $this->rows();
        $columns = ['username', 'person', 'entity', 'role', 'status', 'two_factor', 'last_login', 'online', 'location', 'location_detail', 'ip_location', 'coordinates', 'address', 'flags', 'permission_count', 'permissions'];
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

    /**
     * Where each person was last seen: the newest exact (browser) position of their sessions in the
     * last 90 days, else the newest session's IP place; and whether a session is open and active now.
     *
     * @return array<string, array<string, mixed>> login account id => place fields
     */
    private function lastPlaces(): array
    {
        /** @var Collection<int, AuthSession> $sessions */
        $sessions = AuthSession::query()->where('created_at', '>=', now()->subDays(90))
            ->orderByDesc('created_at')->get();
        $out = [];
        foreach ($sessions->groupBy('login_account_id') as $accountId => $list) {
            $exact = $list->filter(fn (AuthSession $s) => $s->location_source === 'browser')
                ->sortByDesc(fn (AuthSession $s) => $s->location_at ?? $s->created_at)->first();
            $s = $exact ?? $list->first();
            $online = $list->contains(fn (AuthSession $x) => $x->revoked_at === null && $x->expires_at->isFuture()
                && $x->last_seen_at !== null && $x->last_seen_at->gt(now()->subMinutes(15)));
            $when = ($exact->location_at ?? $s->last_seen_at ?? $s->created_at)?->timezone('Asia/Karachi')->format('d M Y H:i');
            $out[(string) $accountId] = [
                'online' => $online ? 'online now' : 'offline',
                'location' => $s->placeLabel() ?? ($s->latitude !== null ? sprintf('%.5F, %.5F', $s->latitude, $s->longitude) : 'unknown'),
                'location_exact' => $exact !== null,
                'location_detail' => $exact !== null
                    ? 'Exact (GPS / Wi-Fi)'.($exact->location_accuracy_m !== null ? ' ±'.number_format($exact->location_accuracy_m).' m' : '').', '.$when
                    : ($s->location_source === 'local' ? 'Mill network, '.$when : 'Rough, from the IP address, '.$when),
                'ip_location' => $s->ipPlaceLabel() ?? (string) ($s->ip ?? ''),
                'coordinates' => $exact !== null ? sprintf('%.6F, %.6F', $exact->latitude, $exact->longitude) : '',
                'address' => (string) ($exact->address ?? ''),
                'map_url' => $s->mapUrl(),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function noPlace(): array
    {
        return ['online' => 'offline', 'location' => 'no sign-in in 90 days', 'location_exact' => false, 'location_detail' => '',
            'ip_location' => '', 'coordinates' => '', 'address' => '', 'map_url' => null];
    }

    private static function cell(mixed $v): mixed
    {
        // Spreadsheet formula injection: text starting with = + - @ is defused.
        return is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
    }
}
