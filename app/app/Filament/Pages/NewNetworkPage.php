<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Auth\EmailCode;
use App\Domain\Auth\FirstSignIn;
use App\Domain\Auth\MfaService;
use App\Domain\Auth\NewNetwork;
use App\Domain\Auth\SignInApprovals;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
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
    use TranslatesLabels;

    protected static ?string $slug = 'new-network';

    protected static ?string $title = 'Verify this sign-in';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.new-network';

    /** @var array<string, mixed>|null */
    public ?array $codeData = [];

    public bool $codeSent = false;

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
        if (! $this->byEmail() && ! $this->canAddEmail() && $this->networkHeld()) {
            $this->startApproval();
        }
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
            $done = app(NewNetwork::class)->verifyCode($this->account(), $session, $this->otp, request()->ip());
            $this->addedNetwork = $done['network'];
            $this->approvedDevice = $done['device'];
        } catch (BusinessRuleViolation $e) {
            $this->otp = '';
            $this->dispatch('rmv-otp-invalid');   // the boxes shake and clear
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->dispatch('rmv-otp-valid');
        $this->finished = true;
    }

    public function startApproval(): void
    {
        $opened = app(SignInApprovals::class)->open($this->account(), $this->session(), EmailCode::NEW_NETWORK);
        $this->approvalId = $opened['approval']->id;
        $this->approvalToken = $opened['token'];
        $this->approvalNumber = $opened['approval']->number;
        $this->approvalState = 'pending';
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
