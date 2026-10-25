<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Assistant\Assistant;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * The action chatbot: ask questions and give instructions in words. Changes are shown as a card and
 * run only after Confirm. Everyone signed in can use it; each answer and action is limited to what
 * that person's role allows.
 */
class AssistantPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Assistant';

    protected static ?string $title = 'Assistant';

    protected static ?string $slug = 'assistant';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.assistant';

    public string $message = '';

    /**
     * Only the server writes the conversation: a change waiting for Confirm cannot be altered in the browser
     * between the card shown and the button pressed.
     *
     * @var list<array{role: string, text: string, links?: list<array{label: string, url: string}>, table?: array<string, mixed>|null, pending?: array<string, mixed>|null, done?: bool}>
     */
    #[Locked]
    public array $messages = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        // An archive-only account, and the product owner (no mill data), have nothing for the assistant to do.
        return $user instanceof LoginAccount && (bool) config('ricemill.assistant.enabled') && ! Dashboard::archiveOnly($user) && ! $user->isPlatformAdmin();
    }

    public function mount(): void
    {
        $this->messages[] = ['role' => 'assistant', 'text' => app(Assistant::class)->aiEnabled()
            ? 'Hello! Ask me anything about the mill or tell me what to do, in English or Roman Urdu. Say "help" to see what I can do.'
            : 'Hello! Type a command, for example "stock", "profit this month", "vehicles inside" or "open payments". Say "help" for the full list.'];
    }

    public function send(): void
    {
        $text = trim($this->message);
        if ($text === '') {
            return;
        }
        $this->message = '';
        $history = array_map(fn (array $m) => ['role' => $m['role'], 'content' => $m['text']], array_slice($this->messages, 1));
        $this->messages[] = ['role' => 'user', 'text' => mb_substr($text, 0, 1000)];
        $reply = app(Assistant::class)->ask($this->me(), $text, $history);
        $this->messages[] = ['role' => 'assistant'] + $reply->toArray();
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
    }

    public function cancel(int $index): void
    {
        if (isset($this->messages[$index]['pending']) && ! ($this->messages[$index]['done'] ?? false)) {
            $this->messages[$index]['done'] = true;
            $this->messages[] = ['role' => 'assistant', 'text' => 'Cancelled. Nothing was changed.'];
        }
    }

    public function aiEnabled(): bool
    {
        return app(Assistant::class)->aiEnabled();
    }

    private function me(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }
}
