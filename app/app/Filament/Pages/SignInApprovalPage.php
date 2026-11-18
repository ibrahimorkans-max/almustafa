<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Auth\EmailCode;
use App\Domain\Auth\SignInApprovals;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Concerns\TranslatesLabels;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use App\Models\SignInApproval;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Where an Owner or administrator approves a new person's first sign-in (App\Domain\Auth\SignInApprovals): opened
 * by scanning the QR code on the new screen (then that request is first) or from the menu. Shows who is signing in
 * and from where, and three numbers: picking the one the new screen shows approves it; a wrong one or "Not me"
 * refuses it and ends that sign-in.
 */
class SignInApprovalPage extends Page
{
    use TranslatesLabels;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'Approve sign-ins';

    protected static ?string $title = 'Approve sign-ins';

    protected static ?string $slug = 'approve-sign-ins';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.sign-in-approvals';

    /** The QR code's token: that request is shown first. */
    #[Url(as: 't')]
    public ?string $token = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        // Owners / administrators; and anybody with a sign-in of their own waiting for "Is it you?".
        return $user instanceof LoginAccount && ($user->hasPermission(SignInApprovals::RIGHT)
            || app(SignInApprovals::class)->pendingFor($user, self::currentSessionId())->isNotEmpty());
    }

    /** This browser's sign-in: a person approves their own held sign-in only from another one. */
    public static function currentSessionId(): ?string
    {
        $id = session()->get(EnsureWebAuthSession::SESSION_KEY);

        return is_string($id) ? $id : null;
    }

    /** The request is the person's own sign-in somewhere else (Google-style "Is it you?"). */
    public function isOwn(SignInApproval $r): bool
    {
        return $r->login_account_id === $this->approver()->id;
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();
        if (! $user instanceof LoginAccount || ! app(SignInApprovals::class)->enabled()) {
            return null;
        }
        $count = app(SignInApprovals::class)->pendingFor($user, self::currentSessionId())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Collection<int, SignInApproval> the open requests, the scanned one first */
    public function requests(): Collection
    {
        $pending = app(SignInApprovals::class)->pendingFor($this->approver(), self::currentSessionId());
        $scanned = $this->token !== null ? app(SignInApprovals::class)->findByToken($this->token) : null;

        return $scanned === null ? $pending : $pending->sortByDesc(fn (SignInApproval $a) => $a->id === $scanned->id)->values();
    }

    /** The scanned request when it is no longer waiting (so the approver is told, not shown nothing). */
    public function scannedState(): ?string
    {
        $scanned = $this->token !== null ? app(SignInApprovals::class)->findByToken($this->token) : null;

        return $scanned === null ? ($this->token !== null ? 'unknown' : null)
            : ($scanned->isOpen() ? null : app(SignInApprovals::class)->status($scanned->id));
    }

    /**
     * Where the sign-in waiting for approval comes from: a first sign-in's record, or the new network's session.
     *
     * @return array<string, mixed>
     */
    public function details(SignInApproval $r): array
    {
        if ($r->purpose !== EmailCode::NEW_NETWORK) {
            return $r->loginAccount->first_sign_in ?? [];
        }
        $s = $r->auth_session_id === null ? null
            : app(TenantContext::class)->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->find($r->auth_session_id));

        return $s === null ? [] : array_filter(['ip' => $s->ip, 'browser' => $s->browser, 'os' => $s->os, 'city' => $s->city,
            'region' => $s->region, 'country' => $s->country_name ?? $s->country, 'latitude' => $s->latitude, 'longitude' => $s->longitude]);
    }

    public function pick(string $approvalId, int $number): void
    {
        try {
            $ok = app(SignInApprovals::class)->pick($this->approver(), $approvalId, $number, self::currentSessionId());
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $ok ? Notification::make()->success()->title(__('Approved. Their screen moves on by itself.'))->send()
            : Notification::make()->danger()->title(__('Wrong number: the sign-in was refused and ended.'))
                ->body(__('If you expected it, ask them to sign in again and pick the number their screen shows.'))->persistent()->send();
        $this->token = null;
    }

    public function deny(string $approvalId): void
    {
        try {
            app(SignInApprovals::class)->deny($this->approver(), $approvalId, self::currentSessionId());
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        Notification::make()->warning()->title(__('Refused: that sign-in was ended.'))->send();
        $this->token = null;
    }

    private function approver(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }
}
