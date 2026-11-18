<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\EmailCode;
use App\Domain\Auth\FirstSignIn;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Concerns\TranslatesLabels;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Support\Values\PakistanIds;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The login's e-mail — the only address any security code goes to (first sign-in, new network, forgotten password) —
 * and mobile number. A login that has no e-mail yet gives it here once (it is kept from the first code on); after
 * that only an administrator changes it (Logins). The SaaS owner, Owners and Super Admins must have both: until they
 * do, they are brought here after signing in.
 *
 * @property Form $emailForm
 * @property Form $codeForm
 * @property Form $phoneForm
 */
class SecurityEmailPage extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesLabels;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'My security e-mail';

    protected static ?string $title = 'My security e-mail';

    protected static ?string $slug = 'security-email';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.security-email';

    /** @var array<string, mixed>|null */
    public ?array $emailData = [];

    /** @var array<string, mixed>|null */
    public ?array $codeData = [];

    /** @var array<string, mixed>|null */
    public ?array $phoneData = [];

    public bool $codeSent = false;

    /** The 6-box code being typed (x-verify.otp). */
    public string $otp = '';

    public bool $justConfirmed = false;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ! $user->is_machine;
    }

    public function mount(): void
    {
        $this->emailForm->fill(['email' => $this->account()->email ?? $this->account()->person?->email]);
        $this->codeForm->fill();
        $this->phoneForm->fill(['phone' => PakistanIds::formatMobile($this->account()->person?->phone) ?? $this->account()->person?->phone]);
    }

    /** @return list<string> */
    protected function getForms(): array
    {
        return ['emailForm', 'codeForm', 'phoneForm'];
    }

    public function emailForm(Form $form): Form
    {
        return $form->statePath('emailData')->schema([
            TextInput::make('email')->label(__('E-mail for security codes'))->email()->required()->maxLength(191)->autocomplete('email')
                ->disabled(fn () => $this->stored() !== null)->dehydrated(fn () => $this->stored() === null)
                ->helperText(fn () => $this->stored() !== null
                    ? __('Only your administrator can change it (Security → Logins).')
                    : __('It is kept from the first code on, for all your security codes. Check it carefully.')),
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

    public function phoneForm(Form $form): Form
    {
        return $form->statePath('phoneData')->schema([
            TextInput::make('phone')->label(__('Mobile number'))->tel()->required()->maxLength(20)->placeholder('0300-1234567')->autocomplete('tel'),
        ]);
    }

    public function account(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }

    /** The login's own e-mail, once it has one. */
    public function stored(): ?string
    {
        $email = $this->account()->email;

        return filled($email) ? $email : null;
    }

    public function confirmed(): bool
    {
        return $this->stored() !== null && $this->account()->email_verified_at !== null;
    }

    public function phone(): ?string
    {
        $phone = $this->account()->person?->phone;

        return filled($phone) ? $phone : null;
    }

    public function sendCode(): void
    {
        $typed = $this->stored() === null ? (string) ($this->emailForm->getState()['email'] ?? '') : null;
        try {
            app(EmailCode::class)->send($this->account(), $typed, $this->sessionId(), EmailCode::CONFIRM_EMAIL, signInInfo: ['ip' => request()->ip()]);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->codeSent = true;
        $this->justConfirmed = false;
        Notification::make()->success()->title(__('E-mail sent to :email.', ['email' => FirstSignIn::masked((string) $this->stored())]))
            ->body(__('Type the 6-digit code from it here. Check the spam folder too.'))->send();
    }

    public function checkCode(): void
    {
        $account = $this->account();
        try {
            app(EmailCode::class)->check($account, $this->otp, $this->sessionId());
        } catch (BusinessRuleViolation $e) {
            $this->otp = '';
            $this->dispatch('rmv-otp-invalid');   // the boxes shake and clear
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        app(AuditLogger::class)->record('auth.security_email.confirmed', $account, meta: ['email' => FirstSignIn::masked((string) $account->email)]);
        $this->codeSent = false;
        $this->justConfirmed = true;
        $this->otp = '';
        $this->dispatch('rmv-otp-valid');
    }

    public function savePhone(): void
    {
        $phone = PakistanIds::normalizeMobile((string) ($this->phoneForm->getState()['phone'] ?? ''));
        $person = $this->account()->person;
        if ($phone === null || $person === null) {
            Notification::make()->title(__('Enter your mobile number, e.g. 0300-1234567.'))->danger()->send();

            return;
        }
        app(TenantContext::class)->runAsSystem(fn () => Person::query()->withoutGlobalScopes()->whereKey($person->id)->toBase()
            ->update(['phone' => $phone, 'updated_at' => now()]));
        $person->forceFill(['phone' => $phone])->syncOriginalAttributes(['phone']);
        app(AuditLogger::class)->record('auth.security_phone.saved', $this->account());
        Notification::make()->success()->title(__('Mobile number saved.'))->send();
    }

    private function sessionId(): ?string
    {
        $id = session()->get(EnsureWebAuthSession::SESSION_KEY);

        return is_string($id) ? $id : null;
    }
}
