<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Print & branding: how the working mill looks on every slip, invoice, voucher and report: its names
 * (English and Urdu), tagline, address and contacts, tax numbers, logo and small emblem, two colours,
 * invoice terms, a note on weighment slips, a footer line, and the bank account on its sales invoices.
 * Each customer's mills print as themselves.
 *
 * @property Form $form
 */
class BrandingPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Print & branding';

    protected static ?string $title = 'Print & branding';

    protected static ?string $slug = 'branding';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.branding';

    private const IMAGE_TYPES = ['image/png', 'image/jpeg'];

    /** The mill's columns this page edits. */
    private const FIELDS = [
        'legal_name', 'name_ur', 'tagline', 'address', 'city', 'province', 'phone', 'email', 'website', 'ntn', 'strn',
        'color_primary', 'color_accent', 'invoice_terms', 'slip_note', 'footer_note', 'bank_title', 'bank_name', 'bank_account', 'bank_iban',
    ];

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->hasPermission('entity.manage') || $user->hasPermission('entity.edit_own'));
    }

    /** The working mill; null when an Owner is in "All firms" mode. */
    public function mill(): ?Entity
    {
        $id = app(TenantContext::class)->entityId();

        return $id === null ? null : Entity::query()->find($id);
    }

    public function mount(): void
    {
        $mill = $this->mill();
        if ($mill !== null) {
            abort_unless(Gate::allows('update', $mill), 403);
            $this->form->fill(['legal_name' => $mill->legal_name ?: $mill->name] + $mill->only(self::FIELDS));
        }
    }

    public function form(Form $form): Form
    {
        $hex = ['regex:/^#[0-9a-fA-F]{6}$/'];

        return $form->statePath('data')->schema([
            Section::make('Name and contacts')->columns(2)->schema([
                TextInput::make('legal_name')->label('Name on documents')->required()->maxLength(160),
                TextInput::make('name_ur')->label('Name in Urdu')->maxLength(160)->extraInputAttributes(['dir' => 'rtl']),
                TextInput::make('tagline')->label('Tagline')->maxLength(160)->placeholder('Quality rice since 1985'),
                TextInput::make('phone')->tel()->maxLength(40),
                TextInput::make('email')->email()->maxLength(160),
                TextInput::make('website')->maxLength(160)->placeholder('www.example.com'),
                Textarea::make('address')->maxLength(300)->rows(2),
                TextInput::make('city')->maxLength(80),
                TextInput::make('province')->maxLength(40),
                TextInput::make('ntn')->label('NTN')->maxLength(20),
                TextInput::make('strn')->label('STRN (only if registered for sales tax)')->maxLength(20),
            ]),
            Section::make('Logo and colours')->columns(2)->schema([
                Placeholder::make('logo_now')->label('Logo now')->content(fn () => $this->preview($this->mill()?->logo_path, '64px')),
                Placeholder::make('emblem_now')->label('Small emblem now')->content(fn () => $this->preview($this->mill()?->emblem_path, '48px')),
                FileUpload::make('logo')->label('New logo (wide, for A4 letterheads)')->storeFiles(false)
                    ->acceptedFileTypes(self::IMAGE_TYPES)->maxSize(2048)->helperText('PNG or JPG, up to 2 MB. A clear background prints best.'),
                FileUpload::make('emblem')->label('New small emblem (square, for slips and receipts)')->storeFiles(false)
                    ->acceptedFileTypes(self::IMAGE_TYPES)->maxSize(1024)->helperText('Empty: the logo is used.'),
                Toggle::make('remove_logo')->label('Remove the logo'),
                Toggle::make('remove_emblem')->label('Remove the emblem'),
                ColorPicker::make('color_primary')->label('Main colour')->rules($hex)->helperText('Title bars, names and totals'),
                ColorPicker::make('color_accent')->label('Second colour')->rules($hex)->helperText('Lines and highlights'),
            ]),
            Section::make('Text on documents')->schema([
                Textarea::make('invoice_terms')->label('Terms on sales invoices')->maxLength(1500)->rows(3)
                    ->helperText('Empty: the standard terms (payment days, weights from the weighbridge slip).'),
                TextInput::make('slip_note')->label('Note on weighment and vehicle slips')->maxLength(300)
                    ->placeholder('Keep this slip until payment. Complaints within 24 hours.'),
                TextInput::make('footer_note')->label('Footer line on every document')->maxLength(200)
                    ->placeholder('Thank you for your business'),
            ]),
            Section::make('Bank account on sales invoices')->columns(2)->schema([
                TextInput::make('bank_title')->label('Account title')->maxLength(120),
                TextInput::make('bank_name')->label('Bank and branch')->maxLength(120),
                TextInput::make('bank_account')->label('Account number')->maxLength(40),
                TextInput::make('bank_iban')->label('IBAN')->maxLength(40)->regex('/^[A-Za-z0-9 ]*$/'),
            ]),
        ]);
    }

    public function save(): void
    {
        $mill = $this->mill();
        abort_if($mill === null, 404);
        abort_unless(Gate::allows('update', $mill), 403);
        $data = $this->form->getState();

        try {
            $files = [];
            foreach (['logo' => 'logo_path', 'emblem' => 'emblem_path'] as $field => $column) {
                if ($data['remove_'.$field] ?? false) {
                    $files[$column] = null;
                }
                $upload = $data[$field] ?? null;
                $upload = is_array($upload) ? (reset($upload) ?: null) : $upload;
                if ($upload instanceof TemporaryUploadedFile) {
                    $files[$column] = $this->storeImage($mill, $field, $upload);
                }
            }
        } catch (BusinessRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        // Blank text is stored as empty (the default applies).
        $fields = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v,
            array_intersect_key($data, array_flip(self::FIELDS)));
        $old = $mill->only(['logo_path', 'emblem_path']);
        $mill->fill($fields + $files)->save();
        foreach ($files as $column => $path) {   // the replaced file goes
            if ($old[$column] !== null && $old[$column] !== $path) {
                Storage::disk('local')->delete($old[$column]);
            }
        }
        app(AuditLogger::class)->record('entity.branding.updated', $mill, null, null, ['files' => array_keys($files)]);
        $this->mount();   // the form again, with the files and remove switches cleared
        Notification::make()->success()->title('Saved. Every document printed from now on uses these details.')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Preview a document')->icon('heroicon-o-eye')->color('gray')
                ->visible(fn () => $this->mill() !== null)
                ->url(fn () => route('filament.admin.branding.preview'), shouldOpenInNewTab: true),
        ];
    }

    /** Checks the file really is a PNG or JPEG picture (not just named so) and keeps it privately. */
    private function storeImage(Entity $mill, string $kind, TemporaryUploadedFile $file): string
    {
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ! in_array($info['mime'], self::IMAGE_TYPES, true)) {
            throw new BusinessRuleViolation('The '.$kind.' must be a PNG or JPG picture.');
        }
        $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
        $path = "branding/{$mill->id}/{$kind}-".now()->format('YmdHis').".{$ext}";
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }

    private function preview(?string $path, string $height): HtmlString
    {
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return new HtmlString('<span class="text-sm text-gray-500">None</span>');
        }
        $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';

        return new HtmlString('<img src="data:'.$mime.';base64,'.base64_encode((string) Storage::disk('local')->get($path))
            .'" style="max-height: '.$height.'; max-width: 240px;" alt="">');
    }
}
