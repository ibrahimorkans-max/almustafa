<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Accounts\PartyStatementReport;
use App\Domain\Accounts\PartyStatementSharing;
use App\Domain\Documents\PrintDelivery;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\StatementShare;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accounts → Party statement: one party's statement for a period, from the books (opening and closing
 * balance from its ledger), filtered by transaction type, product, vehicle, sauda and payment status, in
 * a summary, detailed, vehicle-wise, sauda-wise, payments or contractor view. Print opens the print dialog;
 * the PDF can be downloaded, sent by e-mail (attached) or by WhatsApp (a secure, expiring link), and
 * every sharing is listed with what really happened.
 *
 * People with 'ledger.view' see any party. Payroll ('hr.payroll.run') sees labour contractors only.
 *
 * @property Form $form
 */
class PartyStatementPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Party statement';

    protected static ?string $title = 'Party statement';

    protected static ?string $slug = 'party-statement';

    protected static ?int $navigationSort = 41;

    protected static string $view = 'filament.pages.party-statement';

    #[Url]
    public ?string $party = null;

    #[Url]
    public ?string $show = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ($me->hasPermission('ledger.view') || $me->hasPermission('hr.payroll.run'));
    }

    private static function contractorsOnly(): bool
    {
        $me = Filament::auth()->user();

        return ! ($me instanceof LoginAccount && $me->hasPermission('ledger.view'));
    }

    public function mount(): void
    {
        $today = now('Asia/Karachi');
        $this->form->fill([
            'party_id' => $this->party,
            'from' => $today->copy()->startOfMonth()->toDateString(),
            'to' => $today->toDateString(),
            'type' => 'all', 'payment_status' => 'all',
            'view' => isset(PartyStatementReport::VIEWS[(string) $this->show]) ? $this->show : (self::contractorsOnly() ? 'contractor' : 'detailed'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->columns(5)->statePath('data')->schema([
            Select::make('party_id')->label('Party')->searchable()->live()->columnSpan(2)
                ->getSearchResultsUsing(fn (string $search) => Party::query()
                    ->when(self::contractorsOnly(), fn ($q) => $q->where('is_contractor', true))
                    ->where(fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')->orWhere('code', 'ilike', '%'.$search.'%')->orWhere('contractor_code', 'ilike', '%'.$search.'%'))
                    ->orderBy('name')->limit(50)->get()->mapWithKeys(fn (Party $p) => [$p->id => "{$p->name} ({$p->code})"])->all())
                ->getOptionLabelUsing(fn ($value) => ($p = Party::query()->find($value)) ? "{$p->name} ({$p->code})" : null),
            DatePicker::make('from')->required()->live(),
            DatePicker::make('to')->required()->live()->afterOrEqual('from'),
            Select::make('view')->options(PartyStatementReport::VIEWS)->required()->live()->selectablePlaceholder(false),
            Select::make('type')->label('Transaction type')->options(PartyStatementReport::TYPES)->live()->selectablePlaceholder(false),
            Select::make('product_id')->label('Product')->searchable()->live()->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('vehicle')->label('Vehicle number')->live(onBlur: true)->maxLength(20),
            Select::make('sauda_id')->label('Sauda')->searchable()->live()
                ->options(fn () => filled($this->data['party_id'] ?? null) ? Sauda::query()->where('party_id', $this->data['party_id'])->orderByDesc('created_at')->limit(200)->pluck('number', 'id')->all() : []),
            Select::make('payment_status')->label('Payment status')->options(PartyStatementReport::PAYMENT_STATUSES)->live()->selectablePlaceholder(false),
        ]);
    }

    public function selectedParty(): ?Party
    {
        $id = $this->data['party_id'] ?? null;
        $party = $id ? Party::query()->find($id) : null;
        if ($party !== null && self::contractorsOnly() && ! $party->is_contractor) {
            return null;
        }

        return $party;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(): array
    {
        $to = CarbonImmutable::parse($this->data['to'] ?? now('Asia/Karachi')->toDateString(), 'Asia/Karachi');
        $from = CarbonImmutable::parse($this->data['from'] ?? $to->startOfMonth()->toDateString(), 'Asia/Karachi');

        return [$from->greaterThan($to) ? $to : $from, $to];
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return PartyStatementSharing::cleanFilters((array) $this->data);
    }

    /** @return array<string, mixed>|null */
    public function getStatement(): ?array
    {
        $party = $this->selectedParty();
        if ($party === null) {
            return null;
        }
        [$from, $to] = $this->period();

        return app(PartyStatementReport::class)->build($party, $from, $to, $this->filters());
    }

    /** @return Collection<int, StatementShare> */
    public function getShares(): Collection
    {
        $party = $this->selectedParty();

        return $party === null ? collect() : StatementShare::query()->where('party_id', $party->id)->orderByDesc('created_at')->limit(20)->get();
    }

    protected function getHeaderActions(): array
    {
        $ready = fn () => $this->selectedParty() !== null;

        return [
            Action::make('print')->label('Print')->icon('heroicon-o-printer')->visible($ready)
                ->action(function (): void {
                    [$party, $from, $to, $pdf] = $this->pdf();
                    app(PartyStatementSharing::class)->log($party, 'print', null, $from, $to, $this->filters(), null, 'done', null);
                    PrintDelivery::toPrinter($this, $pdf, PartyStatementSharing::fileName($party, $to));
                }),
            Action::make('download')->label('Download PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')->visible($ready)
                ->action(function (): StreamedResponse {
                    [$party, $from, $to, $pdf] = $this->pdf();
                    app(PartyStatementSharing::class)->log($party, 'pdf', null, $from, $to, $this->filters(), null, 'done', null);

                    return response()->streamDownload(fn () => print $pdf, PartyStatementSharing::fileName($party, $to), ['Content-Type' => 'application/pdf']);
                }),
            Action::make('whatsapp')->label('WhatsApp')->icon('heroicon-o-chat-bubble-left-right')->color('success')->visible($ready)
                ->modalDescription('Sends a secure link to the statement (it expires in 3 days). Without the mill\'s WhatsApp connection, WhatsApp opens with the message ready for you to send.')
                ->fillForm(fn () => ['phone' => $this->selectedParty()?->phone, 'message' => $this->defaultMessage()])
                ->form([
                    TextInput::make('phone')->label('Mobile number')->required()->maxLength(20),
                    Textarea::make('message')->required()->rows(4)->maxLength(800),
                ])
                ->action(function (array $data): void {
                    [$from, $to] = $this->period();
                    try {
                        $r = app(PartyStatementSharing::class)->whatsapp($this->selectedParty() ?? abort(404), (string) $data['phone'], (string) $data['message'], $from, $to, $this->filters());
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    if ($r['url'] !== null) {
                        $this->js('window.open('.json_encode($r['url']).', "_blank", "noopener")');
                    }
                    Notification::make()->title($r['status'] === 'failed' ? 'Not sent' : ($r['status'] === 'accepted' ? 'Handed to WhatsApp' : 'WhatsApp opened'))
                        ->body($r['detail'])->{$r['status'] === 'failed' ? 'danger' : 'success'}()->send();
                }),
            Action::make('email')->label('E-mail')->icon('heroicon-o-envelope')->color('gray')->visible($ready)
                ->fillForm(fn () => ['email' => $this->selectedParty()?->email, 'subject' => $this->defaultSubject(), 'message' => $this->defaultMessage()])
                ->form([
                    TextInput::make('email')->label('E-mail address')->email()->required()->maxLength(190),
                    TextInput::make('subject')->required()->maxLength(190),
                    Textarea::make('message')->required()->rows(5)->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    [$from, $to] = $this->period();
                    try {
                        $r = app(PartyStatementSharing::class)->email($this->selectedParty() ?? abort(404), (string) $data['email'], (string) $data['subject'], (string) $data['message'], $from, $to, $this->filters());
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->title($r['status'] === 'failed' ? 'Not sent' : 'E-mail handed to the mail server')->body($r['detail'])
                        ->{$r['status'] === 'failed' ? 'danger' : 'success'}()->send();
                }),
            Action::make('link')->label('Secure link')->icon('heroicon-o-link')->color('gray')->visible($ready)
                ->action(function (): void {
                    [$from, $to] = $this->period();
                    $party = $this->selectedParty() ?? abort(404);
                    $svc = app(PartyStatementSharing::class);
                    $link = $svc->link($party, $from, $to, $this->filters());
                    $svc->log($party, 'link', null, $from, $to, $this->filters(), null, 'prepared', 'Link made to copy and send by hand.', $link['expires']);
                    Notification::make()->success()->persistent()->title('Secure link (expires '.$link['expires']->timezone('Asia/Karachi')->format('d M H:i').')')
                        ->body($link['url'])->send();
                }),
        ];
    }

    /** @return array{0: Party, 1: CarbonImmutable, 2: CarbonImmutable, 3: string} */
    private function pdf(): array
    {
        $party = $this->selectedParty() ?? abort(404);
        [$from, $to] = $this->period();

        return [$party, $from, $to, app(PartyStatementSharing::class)->pdf($party, $from, $to, $this->filters())];
    }

    private function defaultSubject(): string
    {
        [$from, $to] = $this->period();

        return 'Account statement '.$from->format('d M Y').' – '.$to->format('d M Y');
    }

    private function defaultMessage(): string
    {
        $party = $this->selectedParty();
        [$from, $to] = $this->period();

        return 'Assalam-o-Alaikum '.($party->name ?? '').",\nYour account statement for ".$from->format('d M Y').' to '.$to->format('d M Y')
            .' is attached / at the link below. Please let us know of any difference.';
    }
}
