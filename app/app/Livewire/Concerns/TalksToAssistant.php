<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Domain\Assistant\Assistant;
use App\Domain\Assistant\QuickFormOptions;
use App\Domain\Tenancy\TenantContext;
use App\Models\Assistant\AssistantChat;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Attributes\Locked;

/**
 * The conversation with the assistant, shared by the Assistant page and the floating chat button. Three tabs:
 *  - Chat      today's chat (it carries on from screen to screen);
 *  - Features  everything the assistant can do for this person, with examples to try and the quick buttons;
 *  - History   the person's chats day by day: open one to read it and carry on with it.
 * Chats are kept in the database for each person, in their working mill (App\Models\Assistant\AssistantChat).
 */
trait TalksToAssistant
{
    public string $message = '';

    /** chat | features | history */
    public string $tab = 'chat';

    /**
     * The data that fits each blank of the quick form being filled (QuickFormOptions), by the line's label.
     *
     * @var array<string, list<array{label: string, value: string}>>
     */
    public array $suggest = [];

    /**
     * Only the server writes the conversation: a change waiting for Confirm cannot be altered in the browser
     * between the card shown and the button pressed.
     *
     * @var list<array{role: string, text: string, links?: list<array{label: string, url: string}>, table?: array<string, mixed>|null, pending?: array<string, mixed>|null, done?: bool}>
     */
    #[Locked]
    public array $messages = [];

    /** The chat shown (null: a new one, saved at its first question). */
    #[Locked]
    public ?string $chatId = null;

    public function mountTalksToAssistant(): void
    {
        // Today's latest chat, else a new one (also after "New chat", until its first question).
        $chat = session()->has($this->freshKey()) ? null : AssistantChat::query()->of($this->me()->id)->whereDate('chat_date', $this->today())->latest('updated_at')->first();
        $chat !== null ? $this->load($chat) : $this->fresh();
    }

    public function send(): void
    {
        $text = trim($this->message);
        if ($text === '') {
            return;
        }
        $this->message = '';
        $this->suggest = [];
        $this->tab = 'chat';
        $history = array_map(fn (array $m) => ['role' => $m['role'], 'content' => $m['text']], array_slice($this->messages, 1));
        $this->messages[] = ['role' => 'user', 'text' => mb_substr($text, 0, 1000)];
        $reply = app(Assistant::class)->ask($this->me(), $text, array_slice($history, -20));
        $this->messages[] = ['role' => 'assistant'] + $reply->toArray();
        $this->remember();
    }

    public function confirm(int $index): void
    {
        $pending = $this->messages[$index]['pending'] ?? null;
        if (! is_array($pending) || ($this->messages[$index]['done'] ?? false)) {
            return;
        }
        $this->messages[$index]['done'] = true;
        $reply = app(Assistant::class)->confirm($this->me(), ['tool' => (string) $pending['tool'], 'input' => (array) $pending['input']]);
        $this->messages[] = ['role' => 'assistant'] + $reply->toArray();
        $this->remember();
    }

    public function cancel(int $index): void
    {
        if (isset($this->messages[$index]['pending']) && ! ($this->messages[$index]['done'] ?? false)) {
            $this->messages[$index]['done'] = true;
            $this->messages[] = ['role' => 'assistant', 'text' => 'Cancelled. Nothing was changed.'];
            $this->remember();
        }
    }

    /** A quick button: what is needed first, then the message with blanks in the box. */
    public function quick(string $key): void
    {
        $this->tab = 'chat';
        $start = app(Assistant::class)->quickStart($this->me(), $key);
        $this->messages[] = ['role' => 'assistant'] + $start['reply']->toArray();
        $this->suggest = [];
        if ($start['template'] !== null) {
            $this->message = $start['template'];
            $this->suggest = QuickFormOptions::for($key);
            $this->dispatch('assistant-template');
        }
        $this->remember();
    }

    /** An example from the features list, put in the box to send or change. */
    public function tryExample(string $text): void
    {
        $this->tab = 'chat';
        $this->message = mb_substr($text, 0, 1000);
        $this->dispatch('assistant-template');
    }

    /**
     * Every feature as a button under the greeting: a quick form (blanks to fill), a question asked at once, or an
     * example of a change put in the box to finish.
     *
     * @return list<array{label: string, kind: string, value: string, hint: string}>
     */
    public function starters(): array
    {
        $out = [];
        foreach ($this->features() as $group) {
            foreach ($group['items'] as $f) {
                $example = $f['examples'][0] ?? null;
                if ($f['quick'] !== null) {
                    $out[] = ['label' => $f['title'], 'kind' => 'quick', 'value' => $f['quick'], 'hint' => $f['description']];
                } elseif ($example !== null) {
                    $out[] = ['label' => $f['title'], 'kind' => $f['writes'] ? 'fill' : 'send', 'value' => $example, 'hint' => $f['description']];
                }
            }
        }

        return $out;
    }

    /** A feature button pressed (see starters()). */
    public function start(string $kind, string $value): void
    {
        if ($kind === 'quick') {
            $this->quick($value);
        } elseif ($kind === 'send') {
            $this->message = $value;
            $this->send();
        } else {
            $this->tryExample($value);
        }
    }

    /** Starts a new chat (the one before stays in History). */
    public function clearChat(): void
    {
        $this->fresh();
        session()->put($this->freshKey(), true);
        $this->tab = 'chat';
    }

    /** Opens a chat from History to read it and carry on with it. */
    public function openChat(string $id): void
    {
        $chat = AssistantChat::query()->of($this->me()->id)->whereKey($id)->first();
        if ($chat !== null) {
            $this->load($chat);
            $this->tab = 'chat';
            session()->forget($this->freshKey());
        }
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['chat', 'features', 'history'], true) ? $tab : 'chat';
    }

    public function aiEnabled(): bool
    {
        return app(Assistant::class)->aiEnabled();
    }

    /** @return array<string, array{button: string, icon: string}> */
    public function quickForms(): array
    {
        return app(Assistant::class)->quickForms($this->me());
    }

    /** @return list<array{group: string, items: list<array{title: string, description: string, examples: list<string>, quick: string|null, writes: bool}>}> */
    public function features(): array
    {
        return app(Assistant::class)->features($this->me());
    }

    /** @return array<string, list<array{label: string, url: string}>> */
    public function screens(): array
    {
        return app(Assistant::class)->screens();
    }

    /**
     * This person's chats, newest day first: "Today", "Yesterday", then the date.
     *
     * @return array<string, list<array{id: string, title: string, time: string, count: int, current: bool}>>
     */
    public function history(): array
    {
        $today = $this->today();
        $out = [];
        $chats = AssistantChat::query()->of($this->me()->id)->where('chat_date', '>=', $today->subDays(AssistantChat::KEEP_DAYS)->toDateString())
            ->orderByDesc('chat_date')->orderByDesc('updated_at')->limit(200)->get(['id', 'chat_date', 'title', 'message_count', 'updated_at']);
        foreach ($chats as $chat) {
            $day = CarbonImmutable::parse($chat->chat_date->toDateString(), 'Asia/Karachi');
            $label = match (true) {
                $day->isSameDay($today) => 'Today',
                $day->isSameDay($today->subDay()) => 'Yesterday',
                default => $day->format('l, d M Y'),
            };
            $out[$label][] = ['id' => $chat->id, 'title' => (string) ($chat->title ?: 'Chat'),
                'time' => $chat->updated_at->setTimezone('Asia/Karachi')->format('h:i A'), 'count' => $chat->message_count, 'current' => $chat->id === $this->chatId];
        }

        return $out;
    }

    private function load(AssistantChat $chat): void
    {
        $messages = array_values((array) $chat->messages);
        // A change shown on another day is not confirmed later: the books may have moved since.
        if ($chat->chat_date->toDateString() !== $this->today()->toDateString()) {
            foreach ($messages as $i => $m) {
                if (! empty($m['pending']) && empty($m['done'])) {
                    $messages[$i]['done'] = true;
                }
            }
        }
        $this->messages = $messages !== [] ? $messages : [$this->greeting()];
        $this->chatId = $chat->id;
        $this->message = '';
        $this->suggest = [];
    }

    private function fresh(): void
    {
        $this->suggest = [];
        $this->messages = [$this->greeting()];
        $this->chatId = null;
        $this->message = '';
    }

    /** @return array{role: string, text: string} */
    private function greeting(): array
    {
        return ['role' => 'assistant', 'text' => "Hi there,\nHow can I help you today?\n".(app(Assistant::class)->aiEnabled()
            ? 'Pick one below, or ask in your own words (English or Roman Urdu).'
            : 'Pick one below, or type a command such as "stock" or "vehicles inside".')];
    }

    /** Saves the chat; it stays under the day it began, also when carried on later. */
    private function remember(): void
    {
        $me = $this->me();
        $chat = $this->chatId !== null ? AssistantChat::query()->of($me->id)->whereKey($this->chatId)->first() : null;
        $chat ??= new AssistantChat(['login_account_id' => $me->id, 'chat_date' => $this->today()->toDateString()]);
        $firstQuestion = collect($this->messages)->firstWhere('role', 'user')['text'] ?? null;
        $chat->fill([
            'title' => $firstQuestion !== null ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $firstQuestion)), 0, 120) : $chat->title,
            'messages' => array_slice($this->messages, -200), 'message_count' => count($this->messages),
        ])->save();
        $this->chatId = $chat->id;
        session()->forget($this->freshKey());
        if (random_int(1, 50) === 1) {
            AssistantChat::query()->of($me->id)->where('chat_date', '<', $this->today()->subDays(AssistantChat::KEEP_DAYS)->toDateString())->delete();
        }
    }

    /** Set by "New chat" until the new chat's first question, so the screens after it show the new one. */
    private function freshKey(): string
    {
        return 'assistant.fresh.'.(app(TenantContext::class)->entityId() ?? 'none');
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Karachi')->startOfDay();
    }

    private function me(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }
}
