<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Messaging\Gateways\FirebasePush;
use App\Domain\Messaging\Gateways\WhatsAppCloud;
use App\Domain\Messaging\MessageText;
use App\Domain\Messaging\PartyMessenger;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\PartyMessage;
use App\Support\Values\PakistanIds;
use Filament\Actions\Action as HeaderAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Messages to parties, in one place: switch each kind on or off for the mill (login details, money
 * entries, vehicle unloaded, weights final), choose WhatsApp or SMS, and see every message and how it
 * went. Without a message gateway, a waiting message is sent by hand with one click (WhatsApp opens with
 * the text) and then marked sent.
 */
class PartyMessagesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'Masters';

    protected static ?string $navigationLabel = 'Party messages';

    protected static ?string $title = 'Party messages';

    protected static ?string $slug = 'party-messages';

    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.pages.party-messages';

    /** @var array<string, bool> event => on */
    public array $events = [];

    public string $channel = 'whatsapp';

    public bool $push = true;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('messages.manage');
    }

    public function mount(): void
    {
        $settings = app(SettingsService::class);
        $entity = $this->entityId();
        foreach (array_keys(SettingsService::MESSAGE_EVENTS) as $event) {
            $this->events[$event] = $settings->messageOn($event, $entity);
        }
        $this->channel = $settings->messageChannel($entity);
        $this->push = $settings->pushOn($entity);
    }

    public function updatedPush(bool $value): void
    {
        $entity = $this->entityId();
        if ($entity === null) {
            return;
        }
        app(SettingsService::class)->put('messages.push', $value ? 'on' : 'off', $entity);
        Notification::make()->success()->title("Notifications on parties' devices: ".($value ? 'on' : 'off'))->send();
    }

    /** Switches one kind of message on or off for this mill (saved at once). */
    public function updatedEvents(bool $value, string $event): void
    {
        $entity = $this->entityId();
        if ($entity === null || ! isset(SettingsService::MESSAGE_EVENTS[$event])) {
            Notification::make()->title('Choose a firm first (Switch entity).')->warning()->send();

            return;
        }
        app(SettingsService::class)->put('messages.'.$event, $value ? 'on' : 'off', $entity);
        Notification::make()->success()->title(SettingsService::MESSAGE_EVENTS[$event].': '.($value ? 'on' : 'off'))->send();
    }

    public function updatedChannel(string $value): void
    {
        $entity = $this->entityId();
        if ($entity === null) {
            return;
        }
        app(SettingsService::class)->put(SettingsService::MESSAGE_CHANNEL, $value === 'sms' ? 'sms' : 'whatsapp', $entity);
        Notification::make()->success()->title('Messages go by '.($value === 'sms' ? 'SMS' : 'WhatsApp').'.')->send();
    }

    /** Whether a gateway sends the chosen channel by itself. */
    public function gatewayReady(): bool
    {
        return ($this->channel === 'whatsapp' && WhatsAppCloud::configured($this->entityId())) || (string) config('ricemill.messaging.'.$this->channel.'_url') !== '';
    }

    /** @return array<string, bool> what is connected */
    public function connections(): array
    {
        return ['whatsapp' => WhatsAppCloud::configured($this->entityId()), 'firebase' => FirebasePush::configured(), 'firebase_web' => FirebasePush::webReady()];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Connect (or change) the mill's own WhatsApp number from here.
            HeaderAction::make('connectWhatsApp')->label(fn () => WhatsAppCloud::configured($this->entityId()) ? 'WhatsApp settings' : 'Connect WhatsApp')
                ->icon('heroicon-o-link')->url(fn () => IntegrationsPage::getUrl())->visible(fn () => IntegrationsPage::canAccess()),
            HeaderAction::make('testWhatsApp')->label('Send a test WhatsApp')->icon('heroicon-o-paper-airplane')->color('gray')
                ->visible(fn () => WhatsAppCloud::configured($this->entityId()))
                ->form([TextInput::make('mobile')->label('Mobile number')->placeholder('0300-1234567')->required()])
                ->action(function (array $data): void {
                    $to = PakistanIds::normalizeMobile((string) $data['mobile']);
                    if ($to === null) {
                        Notification::make()->title('Give a Pakistani mobile number, e.g. 0300-1234567.')->danger()->send();

                        return;
                    }
                    $r = (new WhatsAppCloud($this->entityId()))->send($to, MessageText::forTemplate(MessageText::make(app(PartyMessenger::class)->millName($this->entityId()),
                        'Test message', ['Messages to parties are connected.'])));
                    $r['ok'] ? Notification::make()->success()->title('Test sent to '.$to)->send()
                        : Notification::make()->danger()->title('Not sent')->body($r['error'])->send();
                }),
        ];
    }

    private function entityId(): ?string
    {
        return app(TenantContext::class)->entityId();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PartyMessage::query()->with('party'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('party.name')->label('Party')->searchable(),
                TextColumn::make('event')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    'login' => 'Login details', 'money' => 'Money', 'unloaded' => 'Unloaded', 'weighed' => 'Weights', default => $state,
                }),
                TextColumn::make('recipient')->label('To')->fontFamily('mono'),
                TextColumn::make('body')->label('Message')->wrap()->limit(160)
                    ->state(fn (PartyMessage $r) => $r->redacted ? '(login details — shown only once, when made)' : $r->body),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    PartyMessage::SENT => 'success', PartyMessage::MANUAL => 'warning', PartyMessage::FAILED => 'danger', default => 'gray',
                })->formatStateUsing(fn (string $state, PartyMessage $r) => match ($state) {
                    PartyMessage::MANUAL => 'to send by hand', default => $state,
                }.($r->error ? ' — '.$r->error : '')),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    PartyMessage::MANUAL => 'To send by hand', PartyMessage::SENT => 'Sent', PartyMessage::FAILED => 'Failed', PartyMessage::SKIPPED => 'Skipped',
                ]),
                SelectFilter::make('event')->options(['login' => 'Login details', 'money' => 'Money', 'unloaded' => 'Unloaded', 'weighed' => 'Weights']),
            ])
            ->actions([
                Action::make('whatsapp')->label('Send on WhatsApp')->icon('heroicon-o-paper-airplane')->color('success')
                    ->visible(fn (PartyMessage $r) => in_array($r->status, [PartyMessage::MANUAL, PartyMessage::FAILED], true) && ! $r->redacted && $r->body !== null)
                    ->url(fn (PartyMessage $r) => PartyMessenger::whatsappLink($r->recipient, (string) $r->body), shouldOpenInNewTab: true),
                Action::make('markSent')->label('Mark sent')->icon('heroicon-o-check')->color('gray')
                    ->visible(fn (PartyMessage $r) => in_array($r->status, [PartyMessage::MANUAL, PartyMessage::FAILED], true))
                    ->action(function (PartyMessage $r): void {
                        $r->forceFill(['status' => PartyMessage::SENT, 'sent_at' => now(), 'error' => null])->save();
                        Notification::make()->success()->title('Marked sent')->send();
                    }),
            ]);
    }
}
