<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform;

use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Platform\PaymentMethodResource\Pages;
use App\Models\LoginAccount;
use App\Models\SaasPaymentMethod;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The ways customers can pay the product owner (platform admin only): bank accounts, mobile wallets,
 * Raast, each with a QR code (the picture the bank or wallet gave, or one made from a text). Customers
 * see the offered ones on My subscription, on the payment page and on their invoices.
 */
class PaymentMethodResource extends Resource
{
    protected static ?string $model = SaasPaymentMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Payment methods';

    protected static ?string $modelLabel = 'payment method';

    protected static ?string $slug = 'platform/payment-methods';

    protected static ?int $navigationSort = 18;

    private const IMAGE_TYPES = ['image/png', 'image/jpeg'];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Section::make('Account')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(80)->placeholder('Meezan Bank, JazzCash, Raast …'),
                Select::make('kind')->options(SaasPaymentMethod::KINDS)->default('bank')->required()->native(false),
                TextInput::make('account_title')->label('Account title')->maxLength(120),
                TextInput::make('account_number')->label('Account / mobile number')->maxLength(60),
                TextInput::make('iban')->label('IBAN')->maxLength(40)->regex('/^[A-Za-z0-9 ]*$/'),
                TextInput::make('sort')->label('Order')->integer()->default(100),
                Textarea::make('instructions')->maxLength(1000)->rows(2)->columnSpanFull()
                    ->placeholder('Write the invoice number in the description, then press "I\'ve paid".'),
                Toggle::make('active')->label('Shown to customers')->default(true),
            ]),
            Section::make('QR code')->schema([
                Placeholder::make('qr_now')->label('QR now')->visibleOn('edit')
                    ->content(fn (?SaasPaymentMethod $record) => $record?->qrDataUri() !== null
                        ? new HtmlString('<img src="'.$record->qrDataUri().'" style="width: 140px; height: 140px; object-fit: contain;" alt="">')
                        : new HtmlString('<span class="text-sm text-gray-500">None</span>')),
                FileUpload::make('qr_upload')->label('QR picture from the bank or wallet')->storeFiles(false)->image()
                    ->acceptedFileTypes(self::IMAGE_TYPES)->maxSize(2048)->helperText('PNG or JPG. Used before the text below.'),
                TextInput::make('qr_text')->label('Or make a QR from this text')->maxLength(500)
                    ->helperText('A Raast ID, a payment link or the account details: customers scan it with their banking app.'),
                Toggle::make('remove_qr')->label('Remove the QR picture')->visibleOn('edit'),
            ]),
        ]);
    }

    /**
     * The uploaded QR picture is checked (a real PNG or JPEG) and kept privately; the form's own fields go.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withQr(array $data, ?SaasPaymentMethod $record = null): array
    {
        $upload = $data['qr_upload'] ?? null;
        $upload = is_array($upload) ? (reset($upload) ?: null) : $upload;
        $old = $record?->qr_path;
        if ($data['remove_qr'] ?? false) {
            $data['qr_path'] = null;
        }
        if ($upload instanceof TemporaryUploadedFile) {
            $info = @getimagesize($upload->getRealPath());
            if ($info === false || ! in_array($info['mime'], self::IMAGE_TYPES, true)) {
                throw new BusinessRuleViolation('The QR must be a PNG or JPG picture.');
            }
            $path = 'saas-qr/'.bin2hex(random_bytes(8)).($info['mime'] === 'image/png' ? '.png' : '.jpg');
            Storage::disk('local')->put($path, (string) file_get_contents($upload->getRealPath()));
            $data['qr_path'] = $path;
        }
        if ($old !== null && array_key_exists('qr_path', $data) && $data['qr_path'] !== $old) {
            Storage::disk('local')->delete($old);
        }
        unset($data['qr_upload'], $data['remove_qr']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->description(fn (SaasPaymentMethod $r) => SaasPaymentMethod::KINDS[$r->kind] ?? $r->kind)->searchable(),
                TextColumn::make('account_title')->label('Account')->description(fn (SaasPaymentMethod $r) => $r->iban ?: $r->account_number)->placeholder('—'),
                IconColumn::make('qr')->label('QR')->state(fn (SaasPaymentMethod $r) => $r->hasQr())->boolean(),
                TextColumn::make('active')->label('Shown')->badge()->formatStateUsing(fn (bool $state) => $state ? 'shown' : 'hidden')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->actions([
                EditAction::make()->mutateFormDataUsing(fn (array $data, SaasPaymentMethod $record) => self::withQr($data, $record)),
                DeleteAction::make()->after(fn (SaasPaymentMethod $record) => $record->qr_path ? Storage::disk('local')->delete($record->qr_path) : null),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePaymentMethods::route('/')];
    }
}
