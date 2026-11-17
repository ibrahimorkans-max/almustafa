<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Auth\FirstSignIn;
use App\Domain\Auth\MfaService;
use App\Domain\Auth\SignInApprovals;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Concerns\TranslatesLabels;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * A new user's first sign-in (App\Domain\Auth\FirstSignIn), one check after the other: shows where the sign-in came
 * from; sends the e-mail with a 6-digit code and takes the code typed here; shows a QR code and a number for somebody
 * already signed in to approve the sign-in (the page moves on by itself); then asks for the user's own details.
 * Nothing else opens until it is done (EnsureWebAuthSession).
 *
 * @property Form $emailForm
 * @property Form $codeForm
 * @property Form $detailsForm
 */
class FirstSignInPage extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesLabels;

    protected static ?string $slug = 'first-sign-in';

    protected static ?string $title = 'Welcome — finish signing in';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.first-sign-in';

    /** @var array<string, mixed>|null */
    public ?array $emailData = [];

    /** @var array<string, mixed>|null */
    public ?array $codeData = [];

    /** @var array<string, mixed>|null */
    public ?array $detailsData = [];

    public bool $emailSent = false;

    /** Done: the page shows the success animation, then opens the dashboard. */
    public bool $finished = false;

    /** The open approval request of this screen, and its QR code's token (only this screen knows it). */
    public ?string $approvalId = null;

    public ?string $approvalToken = null;

    public ?int $approvalNumber = null;

    public ?string $approvalState = null;

    public function mount(): void
    {
        $account = $this->account();
        if (! $account->needsFirstSignIn()) {
            $this->redirect(Filament::getUrl());

            return;
        }
        $this->emailSent = $account->email_verify_sent_at !== null && $account->email_verified_at === null;
        $this->emailForm->fill(['email' => $account->email ?? $account->person?->email]);
        $this->codeForm->fill();
        $this->detailsForm->fill(['full_name' => $account->person?->full_name, 'phone' => PakistanIds::formatMobile($account->person?->phone) ?? $account->person?->phone]);
        $this->startApproval();
    }

    /** @return list<string> */
    protected function getForms(): array
    {
        return ['emailForm', 'codeForm', 'detailsForm'];
    }

    public function emailForm(Form $form): Form
    {
        return $form->statePath('emailData')->schema([
            // The login's own e-mail (entered by its administrator) is the only address codes go to; without one,
            // the address typed here is kept from the first code on.
            TextInput::make('email')->label(__('Your e-mail address'))->email()->required()->maxLength(191)->autocomplete('email')
                ->disabled(fn () => filled($this->account()->email))->dehydrated(fn () => blank($this->account()->email))
                ->helperText(fn () => filled($this->account()->email)
                    ? __('The 6-digit code goes to the e-mail on your login. Wrong address? Ask your administrator to correct it.')
                    : __('A 6-digit code is sent here. Type it below, on this screen. This address is kept for all your security codes.')),
        ]);
    }

    public function codeForm(Form $form): Form
    {
        return $form->statePath('codeData')->schema([
            TextInput::make('code')->label(__('Code from the e-mail'))->required()->length(6)
                ->autocomplete('one-time-code')->extraInputAttributes(['inputmode' => 'numeric', 'dir' => 'ltr', 'pattern' => '[0-9]*'])
                ->placeholder('123456'),
        ]);
    }

    public function detailsForm(Form $form): Form
    {
        return $form->statePath('detailsData')->columns(['default' => 1, 'sm' => 2])->schema([
            TextInput::make('full_name')->label(__('Full name'))->required()->minLength(3)->maxLength(150)->autocomplete('name'),
            TextInput::make('phone')->label(__('Mobile number'))->tel()->required()->maxLength(20)->placeholder('0300-1234567')->autocomplete('tel'),
        ]);
    }

    public function account(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }

    public function verified(): bool
    {
        return $this->account()->email_verified_at !== null;
    }

    /** The current step: email, approval or details. */
    public function step(): string
    {
        return match (true) {
            ! $this->verified() => 'email',
            app(SignInApprovals::class)->needed($this->account()) => 'approval',
            default => 'details',
        };
    }

    /** Whether this sign-in is approved by somebody else at all (it is not when nobody else could). */
    public function hasApprovalStep(): bool
    {
        $approvals = app(SignInApprovals::class);

        return $approvals->enabled() && ($this->account()->first_sign_in_approved_at !== null || $approvals->approvers($this->account())->isNotEmpty());
    }

    /** @return array<string, mixed> where the first sign-in came from */
    public function info(): array
    {
        return $this->account()->first_sign_in ?? [];
    }

    public function sendEmail(): void
    {
        $email = (string) ($this->emailForm->getState()['email'] ?? '');
        try {
            app(FirstSignIn::class)->sendVerification($this->account(), $email, $this->sessionId());
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->emailSent = true;
        Notification::make()->success()->title(__('E-mail sent to :email.', ['email' => FirstSignIn::masked((string) $this->account()->email)]))
            ->body(__('Type the 6-digit code from it here. Check the spam folder too.'))->send();
    }

    public function checkCode(): void
    {
        $code = (string) ($this->codeForm->getState()['code'] ?? '');
        try {
            app(FirstSignIn::class)->verifyCode($this->account(), $code, $this->sessionId());
        } catch (BusinessRuleViolation $e) {
            $this->codeForm->fill();
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->emailSent = false;
        Notification::make()->success()->title(__('E-mail confirmed.'))->send();
        $this->startApproval();
    }

    /** Opens (again, when the old one is too old or was refused) the request somebody signed in approves. */
    public function startApproval(): void
    {
        if ($this->step() !== 'approval') {
            return;
        }
        $opened = app(SignInApprovals::class)->open($this->account(), $this->session());
        $this->approvalId = $opened['approval']->id;
        $this->approvalToken = $opened['token'];
        $this->approvalNumber = $opened['approval']->number;
        $this->approvalState = 'pending';
    }

    /** Asked every few seconds while the approval is waiting: the page moves on once it is approved. */
    public function checkApproval(): void
    {
        if ($this->approvalId === null) {
            $this->startApproval();

            return;
        }
        $this->approvalState = app(SignInApprovals::class)->status($this->approvalId);
        if ($this->approvalState === 'approved') {
            Notification::make()->success()->title(__('Sign-in approved.'))->send();
        }
    }

    /** The QR code of this request (SVG): the approver's phone opens Approve sign-ins at it. */
    public function approvalQr(): ?string
    {
        return $this->approvalToken === null ? null
            : app(MfaService::class)->qrSvg(SignInApprovalPage::getUrl(['t' => $this->approvalToken]));
    }

    /** The browser's own position, sent by the page once it is allowed. */
    public function saveLocation(mixed $lat, mixed $lng, mixed $accuracy = null): void
    {
        app(FirstSignIn::class)->addLocation($this->account(), $lat, $lng, $accuracy);
    }

    public function saveDetails(): void
    {
        $data = $this->detailsForm->getState();
        try {
            $session = $this->session();
            app(FirstSignIn::class)->complete($this->account(), ['full_name' => (string) $data['full_name'], 'phone' => (string) $data['phone']],
                $session?->login_account_id === $this->account()->id ? $session : null);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->finished = true;
    }

    private function sessionId(): ?string
    {
        $id = session()->get(EnsureWebAuthSession::SESSION_KEY);

        return is_string($id) ? $id : null;
    }

    private function session(): ?AuthSession
    {
        $id = $this->sessionId();

        return $id === null ? null : app(TenantContext::class)->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->find($id));
    }
}
