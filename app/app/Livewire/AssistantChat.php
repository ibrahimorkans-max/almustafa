<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Pages\AssistantPage;
use App\Livewire\Concerns\TalksToAssistant;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The floating chat button at the bottom corner of every screen: the assistant in a small window, with the
 * quick buttons (Add new party, Vehicle arrived…) that write a message with blanks to fill. The conversation
 * is the same as on the Assistant page.
 */
class AssistantChat extends Component
{
    use TalksToAssistant;

    public function render(): View
    {
        return view('livewire.assistant-chat', ['fullUrl' => AssistantPage::getUrl()]);
    }
}
