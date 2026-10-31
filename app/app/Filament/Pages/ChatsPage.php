<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\PartyMessage;
use App\Models\WhatsAppEvent;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Chats: every message sent to a party (WhatsApp or SMS, with how it went and whether WhatsApp delivered it or
 * it was read) and every WhatsApp message a party sent back (through the webhook), as one conversation per
 * party, newest first. Login details are never shown again (only that they were sent).
 */
class ChatsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Masters';

    protected static ?string $navigationLabel = 'Chats';

    protected static ?string $title = 'Chats';

    protected static ?string $slug = 'chats';

    protected static ?int $navigationSort = 16;

    protected static string $view = 'filament.pages.chats';

    /** The conversation open: "p:{party id}", or "n:{phone}" for a number that is no party. */
    #[Url]
    public ?string $chat = null;

    public string $search = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('messages.manage');
    }

    private function entityId(): ?string
    {
        return app(TenantContext::class)->entityId();
    }

    /** @return list<array{key: string, name: string, phone: string|null, last: Carbon, preview: string, count: int, unread: int}> newest first */
    public function getConversations(): array
    {
        $entity = $this->entityId();
        if ($entity === null) {
            return [];
        }
        $chats = [];
        $sent = PartyMessage::query()->where('entity_id', $entity)
            ->selectRaw('party_id, max(created_at) as last_at, count(*) as n')->groupBy('party_id')->toBase()->get();
        foreach ($sent as $row) {
            $chats['p:'.$row->party_id] = ['last' => Carbon::parse($row->last_at), 'count' => (int) $row->n, 'unread' => 0];
        }
        $received = WhatsAppEvent::query()->where('entity_id', $entity)->where('kind', WhatsAppEvent::MESSAGE)
            ->selectRaw('party_id, phone, max(occurred_at) as last_at, count(*) as n')->groupBy('party_id', 'phone')->toBase()->get();
        foreach ($received as $row) {
            $key = $row->party_id !== null ? 'p:'.$row->party_id : 'n:'.$row->phone;
            $last = Carbon::parse($row->last_at);
            $chats[$key] = [
                'last' => isset($chats[$key]) && $chats[$key]['last']->gt($last) ? $chats[$key]['last'] : $last,
                'count' => ($chats[$key]['count'] ?? 0) + (int) $row->n,
                'unread' => ($chats[$key]['unread'] ?? 0) + (int) $row->n,
            ];
        }
        $parties = Party::query()->whereIn('id', collect(array_keys($chats))->filter(fn ($k) => str_starts_with($k, 'p:'))->map(fn ($k) => substr($k, 2))->all())
            ->get(['id', 'name', 'code', 'phone'])->keyBy('id');
        $needle = mb_strtolower(trim($this->search));
        $out = [];
        foreach ($chats as $key => $c) {
            $party = str_starts_with($key, 'p:') ? $parties[substr($key, 2)] ?? null : null;
            $name = $party !== null ? $party->name : 'Unknown number';
            $phone = $party !== null ? $party->phone : substr($key, 2);
            if ($needle !== '' && ! str_contains(mb_strtolower($name.' '.$phone.' '.($party->code ?? '')), $needle)) {
                continue;
            }
            $out[] = ['key' => $key, 'name' => $name, 'phone' => $phone, 'last' => $c['last'], 'count' => $c['count'], 'unread' => $c['unread'], 'preview' => ''];
        }
        usort($out, fn ($a, $b) => $b['last'] <=> $a['last']);
        $out = array_slice($out, 0, 200);
        foreach ($out as &$c) {
            $c['preview'] = $this->preview($c['key']);
        }

        return $out;
    }

    /** @return list<array{out: bool, at: Carbon, text: string, channel: string, status: string|null, delivery: string|null, error: string|null}> oldest first */
    public function getThread(): array
    {
        $key = $this->chat;
        $entity = $this->entityId();
        if ($key === null || $entity === null || strlen($key) < 3) {
            return [];
        }
        $byParty = str_starts_with($key, 'p:');
        $value = substr($key, 2);
        $lines = [];
        if ($byParty) {
            foreach (PartyMessage::query()->where('entity_id', $entity)->where('party_id', $value)->latest('created_at')->limit(200)->get() as $m) {
                $lines[] = ['out' => true, 'at' => $m->created_at, 'text' => $m->redacted ? '(login details: shown only once, when they were made)' : (string) $m->body,
                    'channel' => $m->channel, 'status' => $m->status, 'delivery' => $m->delivery_status, 'error' => $m->error,
                    'what' => $m->event];
            }
        }
        $received = WhatsAppEvent::query()->where('entity_id', $entity)->where('kind', WhatsAppEvent::MESSAGE)
            ->when($byParty, fn ($q) => $q->where('party_id', $value), fn ($q) => $q->whereNull('party_id')->where('phone', $value))
            ->latest('occurred_at')->limit(200)->get();
        foreach ($received as $e) {
            $lines[] = ['out' => false, 'at' => $e->occurred_at, 'text' => $e->body ?? '('.$e->type.')', 'channel' => 'whatsapp',
                'status' => null, 'delivery' => null, 'error' => null, 'what' => null];
        }
        usort($lines, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $lines;
    }

    /** @return array{name: string, phone: string|null}|null */
    public function getChatHeader(): ?array
    {
        if ($this->chat === null) {
            return null;
        }
        if (str_starts_with($this->chat, 'p:')) {
            $party = Party::query()->find(substr($this->chat, 2));

            return $party === null ? null : ['name' => $party->name, 'phone' => $party->phone];
        }

        return ['name' => 'Unknown number', 'phone' => substr($this->chat, 2)];
    }

    public function open(string $key): void
    {
        $this->chat = $key;
    }

    private function preview(string $key): string
    {
        $thread = $this->threadTail($key);

        return $thread === null ? '' : mb_strimwidth(preg_replace('/\s+/u', ' ', $thread) ?? '', 0, 70, '…');
    }

    private function threadTail(string $key): ?string
    {
        $entity = (string) $this->entityId();
        $value = substr($key, 2);
        $sent = str_starts_with($key, 'p:')
            ? PartyMessage::query()->where('entity_id', $entity)->where('party_id', $value)->latest('created_at')->first() : null;
        $got = WhatsAppEvent::query()->where('entity_id', $entity)->where('kind', WhatsAppEvent::MESSAGE)
            ->when(str_starts_with($key, 'p:'), fn ($q) => $q->where('party_id', $value), fn ($q) => $q->whereNull('party_id')->where('phone', $value))
            ->latest('occurred_at')->first();
        if ($got !== null && ($sent === null || $got->occurred_at->gt($sent->created_at))) {
            return '↩ '.($got->body ?? '('.$got->type.')');
        }

        return $sent === null ? null : ($sent->redacted ? 'Login details sent' : (string) $sent->body);
    }
}
