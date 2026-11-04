<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Livewire\Concerns\TalksToAssistant;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * The action chatbot: ask questions and give instructions in words. Changes are shown as a card and
 * run only after Confirm. Everyone signed in can use it; each answer and action is limited to what
 * that person's role allows. The same conversation is on the floating chat button of every screen.
 */
class AssistantPage extends Page
{
    use TalksToAssistant;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Assistant';

    protected static ?string $title = 'Assistant';

    protected static ?string $slug = 'assistant';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.assistant';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        // An archive-only account, and the product owner (no mill data), have nothing for the assistant to do.
        return $user instanceof LoginAccount && (bool) config('ricemill.assistant.enabled') && ! Dashboard::archiveOnly($user) && ! $user->isPlatformAdmin();
    }
}
