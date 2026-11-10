<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\VoucherLine;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use App\Support\Values\PakistanIds;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Accounts → Bank accounts: the mill's own bank accounts — the ledgers payments are made from and receipts paid
 * into — added with a simple form (bank, account title, IBAN), each with its balance. Behind it each is a ledger
 * of the kind "Mill bank account" in the Bank Accounts group, with its own code; the IBAN is kept encrypted.
 */
class BankAccountsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Bank accounts';

    protected static ?string $title = 'Mill bank accounts';

    protected static ?string $slug = 'accounts/bank-accounts';

    protected static ?int $navigationSort = 45;

    protected static string $view = 'filament.pages.bank-accounts';

    /** Banks in Pakistan, for the choice (any other can be typed). */
    public const BANKS = ['Meezan Bank', 'HBL (Habib Bank)', 'UBL (United Bank)', 'MCB Bank', 'Allied Bank', 'Bank Alfalah', 'National Bank of Pakistan',
        'Bank Al Habib', 'Askari Bank', 'Faysal Bank', 'Standard Chartered', 'Bank of Punjab', 'JS Bank', 'Soneri Bank', 'Habib Metro Bank',
        'Dubai Islamic Bank', 'BankIslami', 'Al Baraka Bank', 'Summit Bank', 'Silk Bank', 'Zarai Taraqiati Bank (ZTBL)', 'Sindh Bank', 'Bank of Khyber'];

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->hasPermission('ledger.view');
    }

    private static function canAdd(): bool
    {
        return (Filament::auth()->user()?->hasPermission('ledger.post') ?? false) && app(TenantContext::class)->entityId() !== null;
    }

    /** A ledger's balance now: debits less credits (a bank account in credit shows as money held). */
    public static function balance(LedgerAccount $ledger): int
    {
        $sums = VoucherLine::query()->where('ledger_account_id', $ledger->id)->selectRaw('coalesce(sum(dr_paise), 0) as dr, coalesce(sum(cr_paise), 0) as cr')->first();

        return (int) ($sums->dr ?? 0) - (int) ($sums->cr ?? 0);
    }

    public function table(Table $table): Table
    {
        return $table->query(fn (): Builder => LedgerAccount::query()->where('kind', 'bank')->orderBy('name'))
            ->columns([
                TextColumn::make('name')->label('Account')->searchable()->description(fn (LedgerAccount $r) => $r->code),
                TextColumn::make('bank_name')->label('Bank')->placeholder('—'),
                TextColumn::make('bank_account_last4')->label('IBAN')->formatStateUsing(fn (?string $state) => $state ? '•••• '.$state : '—'),
                TextColumn::make('balance')->label('Balance')->alignEnd()->fontFamily('mono')
                    ->state(fn (LedgerAccount $r) => self::balance($r))
                    ->formatStateUsing(fn (int $state) => ($state < 0 ? '− ' : '').Money::paise(abs($state))->format())
                    ->color(fn (int $state) => $state < 0 ? 'danger' : null),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->actions([
                Action::make('edit')->label('Edit')->icon('heroicon-m-pencil-square')->visible(fn () => self::canAdd())
                    ->fillForm(fn (LedgerAccount $r) => ['name' => $r->name, 'bank_name' => $r->bank_name, 'status' => $r->status])
                    ->form([
                        TextInput::make('name')->label('Account title')->required()->maxLength(160),
                        TextInput::make('bank_name')->label('Bank')->maxLength(120)->datalist(self::BANKS),
                        Select::make('status')->options(['active' => 'In use', 'inactive' => 'Not used any more'])->required(),
                    ])
                    ->action(function (LedgerAccount $r, array $data): void {
                        $r->fill($data)->save();
                        Notification::make()->success()->title('Saved.')->send();
                    }),
            ])
            ->emptyStateHeading('No bank account yet')
            ->emptyStateDescription('Add the mill\'s bank accounts: payments are made from them and receipts paid into them.');
    }

    public function addAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('add')->label('Add bank account')->icon('heroicon-o-plus')
            ->visible(fn () => self::canAdd())
            ->modalDescription('The mill\'s own account at a bank. The IBAN is kept encrypted; only its last four characters are shown.')
            ->form([
                TextInput::make('bank_name')->label('Bank')->required()->maxLength(120)->datalist(self::BANKS)->placeholder('Meezan Bank'),
                TextInput::make('title')->label('Account title / purpose')->required()->maxLength(100)->placeholder('Current account – Lodhran branch'),
                TextInput::make('iban')->label('IBAN')->required()->maxLength(34)->placeholder('PK36 MEZN 0000 0011 2345 6702')
                    ->rule(fn () => fn (string $a, mixed $v, Closure $fail) => $v && ! PakistanIds::isValidIban((string) PakistanIds::normalizeIban((string) $v))
                        ? $fail('The IBAN is not valid (PK and 22 characters; check it on the bank\'s cheque book or app).') : null),
                TextInput::make('code')->label('Short code (optional)')->maxLength(40)->regex('/^[A-Za-z0-9._-]+$/')
                    ->placeholder(fn (Get $get) => self::codeFor((string) $get('bank_name'))),
            ])
            ->action(function (array $data): void {
                $entity = (string) app(TenantContext::class)->entityId();
                $group = AccountGroup::query()->where('code', 'BANK_ACCOUNTS')->where('entity_id', $entity)->first()
                    ?? AccountGroup::query()->where('code', 'BANK_ACCOUNTS')->first();
                if ($group === null) {
                    Notification::make()->danger()->title('This mill has no "Bank Accounts" group in its chart of accounts.')->send();

                    return;
                }
                $code = strtoupper(trim((string) ($data['code'] ?? ''))) ?: self::codeFor((string) $data['bank_name']);
                if (LedgerAccount::query()->where('code', $code)->exists()) {
                    Notification::make()->danger()->title("The code {$code} is taken; give another.")->send();

                    return;
                }
                $iban = PakistanIds::normalizeIban((string) $data['iban']);
                if (LedgerAccount::query()->where('kind', 'bank')->get()->contains(fn (LedgerAccount $l) => $l->bank_account_number === $iban)) {
                    Notification::make()->danger()->title('This IBAN is already one of the mill\'s bank accounts.')->send();

                    return;
                }
                $ledger = DB::transaction(fn () => LedgerAccount::query()->create([
                    'entity_id' => $group->entity_id ?? $entity, 'group_id' => $group->id, 'code' => $code, 'kind' => 'bank',
                    'name' => trim((string) $data['bank_name']).' — '.trim((string) $data['title']), 'bank_name' => trim((string) $data['bank_name']),
                    'bank_account_number' => $iban, 'status' => 'active',
                ]));
                app(AuditLogger::class)->record('accounts.bank_account_added', $ledger, null, ['code' => $ledger->code, 'bank' => $ledger->bank_name]);
                Notification::make()->success()->title('Bank account added: '.$ledger->name)->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->addAction()];
    }

    /** MEZN-1, HBL-2…: the bank's first word and the next number. */
    private static function codeFor(string $bank): string
    {
        $word = strtoupper((string) preg_replace('/[^A-Za-z]/', '', explode(' ', trim($bank) ?: 'BANK')[0])) ?: 'BANK';
        $n = LedgerAccount::query()->where('kind', 'bank')->count() + 1;
        while (LedgerAccount::query()->where('code', "BANK-{$word}-{$n}")->exists()) {
            $n++;
        }

        return "BANK-{$word}-{$n}";
    }
}
