<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Auth\EmailCode;
use App\Domain\Auth\FirstSignIn;
use App\Domain\Auth\MfaService;
use App\Domain\Auth\MobileCode;
use App\Domain\Auth\NewNetwork;
use App\Domain\Auth\SecondFactor;
use App\Domain\Auth\SignInApprovals;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Concerns\ProvesSecondFactor;
use App\Filament\Concerns\TranslatesLabels;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;

/**
 * A sign-in from a network the login may not use, held here (App\Domain\Auth\NewNetwork) with no rights: the person
 * types the 6-digit code sent to their login's own e-mail — or, without one, somebody signed in approves it with the QR
 * code and number. The network is then added and the dashboard opens.
 *
 * @property Form $codeForm
 */
class NewNetworkPage extends Page implements HasForms
{
    use InteractsWithForms;
    use ProvesSecondFactor;
    use TranslatesLabels;

    protected static ?string $slug = 'new-network';

    protected static ?string $title = 'Verify this sign-in';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.new-network';

    /** @var array<string, mixed>|null */
    public ?array $codeData = [];

    public bool $codeSent = false;

    /** The second check by the mobile (MobileCode): sent, and the code being typed. */
    public bool $mobileSent = false;

    public string $mobileOtp = '';

    /** The e-mail a login without one gives here (NewNetwork::canAddEmail); kept from the first code on. */
    public string $newEmail = '';

    /** The 6-box code being typed (x-verify.otp). */
    public string $otp = '';

    public bool $finished = false;

    public ?string $addedNetwork = null;

    public ?string $approvedDevice = null;

    public ?string $approvalId = null;

    public ?string $approvalToken = null;

    public ?int $approvalNumber = null;

    public ?string $approvalState = null;

    public function mount(): void
    {
        if (! $this->held()) {
            // A plain redirect: Livewire's own one cannot leave a page while it is first opened.
            throw new HttpResponseException(new RedirectResponse(Filament::getUrl()));
        }
        $this->codeForm->fill();
        if ($this->stage() === 'approval') {
            $session = $this->session();
            if ($session !== null && $session->code_proven_at !== null && ! app(NewNetwork::class)->approvalNeeded($this->account(), $session)) {
                $this->moveOn(app(NewNetwork::class)->next($this->account(), $session, request()->ip()));   // all checks done already
            } else {
                $this->startApproval();
            }
        }
    }

    /**
     * Where this held sign-in stands: 'email' (the code first), 'approval' (then the QR code + number on a device
     * already signed in), or 'done'.
     */
    public function stage(): string
    {
        return match (true) {
            $this->finished => 'done',
            $this->session()?->code_proven_at === null && ($this->byEmail() || $this->canAddEmail()) => 'email',
            ! app(SecondFactor::class)->proven($this->account(), $this->session()?->id) => 'factor',
            default => 'approval',
        };
    }

    public function hasFactorStep(): bool
    {
        return app(SecondFactor::class)->required($this->account());
    }

    /** The 6-digit code was right: the e-mail's 2-digit number is picked now. */
    public function numberPending(): bool
    {
        return app(EmailCode::class)->numberPending($this->account(), $this->session()?->id);
    }

    /** @return list<int> */
    public function numberChoices(): array
    {
        return app(EmailCode::class)->choices($this->account());
    }

    public function pickNumber(int $number): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        try {
            $done = app(NewNetwork::class)->verifyNumber($this->account(), $session, $number, request()->ip());
        } catch (BusinessRuleViolation $e) {
            $this->codeSent = false;   // the e-mail's codes are cancelled: a new one is sent
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->moveOn($done);
    }

    protected function factorAccount(): LoginAccount
    {
        return $this->account();
    }

    protected function factorKey(): string
    {
        return (string) $this->session()?->id;
    }

    protected function factorProven(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        try {
            $this->moveOn(app(NewNetwork::class)->afterSecondFactor($this->account(), $session, request()->ip()));
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();
        }
    }

    /** @param  array{stage: string, network: string|null, device: string|null}  $done */
    private function moveOn(array $done): void
    {
        $this->addedNetwork = $done['network'];
        $this->approvedDevice = $done['device'];
        if ($done['stage'] === 'approval') {
            $this->startApproval();
        }
        $this->finished = $done['stage'] === 'done';
    }

    /** Whether the QR code + number is one of this sign-in's checks (somebody can approve it). */
    public function hasApprovalStep(): bool
    {
        $session = $this->session();

        return $session !== null && (! ($this->byEmail() || $this->canAddEmail()) || app(NewNetwork::class)->approvalNeeded($this->account(), $session));
    }

    /** Whether the person has another device signed in that can say "it is me" (Google-style). */
    public function hasOwnDevice(): bool
    {
        return app(SignInApprovals::class)->ownSessions($this->account(), $this->session()?->id)->isNotEmpty();
    }

    /** @return list<string> */
    protected function getForms(): array
    {
        return ['codeForm'];
    }

    public function codeForm(Form $form): Form
    {
        return $form->statePath('codeData')->schema([
            TextInput::make('code')->label(__('Code from the e-mail'))->required()->length(6)
                ->autocomplete('one-time-code')->extraInputAttributes(['inputmode' => 'numeric', 'dir' => 'ltr', 'pattern' => '[0-9]*'])
                ->placeholder('123456'),
        ]);
    }

    public function account(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }

    public function byEmail(): bool
    {
        return app(NewNetwork::class)->byEmail($this->account());
    }

    /** No e-mail on the login yet: the person may give one here (after the password and the authenticator code). */
    public function canAddEmail(): bool
    {
        return app(NewNetwork::class)->canAddEmail($this->account());
    }

    public function maskedEmail(): string
    {
        return FirstSignIn::masked((string) $this->account()->email);
    }

    public function held(): bool
    {
        return $this->networkHeld() || $this->deviceHeld();
    }

    /** Signed in from a network the login may not use. */
    public function networkHeld(): bool
    {
        return $this->session()?->network_pending_at !== null;
    }

    /** Signed in on a device not approved yet: the e-mail code approves it. */
    public function deviceHeld(): bool
    {
        return $this->session()?->device_pending_at !== null;
    }

    /** @return array<string, mixed> where this sign-in comes from */
    public function info(): array
    {
        $s = $this->session();

        return $s === null ? [] : array_filter(['ip' => $s->ip, 'browser' => $s->browser, 'os' => $s->os,
            'place' => implode(', ', array_filter([$s->city, $s->region, $s->country_name ?? $s->country]))]);
    }

    public function sendCode(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        try {
            app(NewNetwork::class)->sendCode($this->account(), $session, $this->byEmail() ? null : $this->newEmail);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->codeSent = true;
        Notification::make()->success()->title(__('E-mail sent to :email.', ['email' => $this->maskedEmail()]))
            ->body(__('Type the 6-digit code from it here. Check the spam folder too.'))->send();
    }

    public function checkCode(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        try {
            app(NewNetwork::class)->verifyCode($this->account(), $session, $this->otp);
        } catch (BusinessRuleViolation $e) {
            $this->otp = '';
            $this->dispatch('rmv-otp-invalid');   // the boxes shake and clear
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->otp = '';
        $this->dispatch('rmv-otp-valid');   // the 2-digit number of the same e-mail comes next
    }

    public function startApproval(): void
    {
        // Nobody could scan a QR code (no other device signed in, no Owner / administrator): the mobile code alone.
        if (! app(SignInApprovals::class)->canBeApproved($this->account(), $this->session()?->id)) {
            $this->approvalState = null;

            return;
        }
        $opened = app(SignInApprovals::class)->open($this->account(), $this->session(), EmailCode::NEW_NETWORK);
        $this->approvalId = $opened['approval']->id;
        $this->approvalToken = $opened['token'];
        $this->approvalNumber = $opened['approval']->number;
        $this->approvalState = 'pending';
    }

    /** Whether the code to the mobile can be the second check (a mobile on the login; WhatsApp or SMS set up). */
    public function mobileAvailable(): bool
    {
        return app(MobileCode::class)->available($this->account());
    }

    public function maskedMobile(): string
    {
        return app(MobileCode::class)->masked($this->account());
    }

    public function sendMobileCode(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        try {
            app(MobileCode::class)->send($this->account(), $session);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->mobileSent = true;
        Notification::make()->success()->title(__('Code sent to your mobile :number.', ['number' => $this->maskedMobile()]))->send();
    }

    public function checkMobileCode(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        try {
            $done = app(NewNetwork::class)->verifyMobile($this->account(), $session, $this->mobileOtp);
        } catch (BusinessRuleViolation $e) {
            $this->mobileOtp = '';
            $this->dispatch('rmv-otp-invalid');
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->addedNetwork = $done['network'];
        $this->approvedDevice = $done['device'];
        $this->dispatch('rmv-otp-valid');
        $this->finished = true;
    }

    /** Asked every few seconds while the approval is waiting. */
    public function checkApproval(): void
    {
        if ($this->approvalId === null) {
            return;
        }
        $this->approvalState = app(SignInApprovals::class)->status($this->approvalId);
        if ($this->approvalState === 'approved') {
            $this->finished = true;
        }
    }

    public function approvalQr(): ?string
    {
        return $this->approvalToken === null ? null
            : app(MfaService::class)->qrSvg(SignInApprovalPage::getUrl(['t' => $this->approvalToken]));
    }

    private function session(): ?AuthSession
    {
        $id = session()->get(EnsureWebAuthSession::SESSION_KEY);

        return is_string($id) ? app(TenantContext::class)->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->find($id)) : null;
    }
}
