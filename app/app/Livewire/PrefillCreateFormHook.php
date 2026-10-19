<?php

declare(strict_types=1);

namespace App\Livewire;

use Filament\Resources\Pages\CreateRecord;
use Livewire\ComponentHook;
use Throwable;

/**
 * Lets the assistant open any "new" form already filled in: `?prefill[field]=value` on a create page
 * fills those fields after the form's own defaults. Only fields that exist on the form are taken;
 * nothing is saved until the person checks the form and presses Create.
 */
final class PrefillCreateFormHook extends ComponentHook
{
    private bool $done = false;

    /**
     * Applied at the first render, i.e. after the page's own mount has filled the form's defaults (a
     * mount hook may run before the page's mount, depending on the order hooks were registered).
     */
    public function render(): void
    {
        $component = $this->component;
        $prefill = request()->query('prefill');
        if ($this->done || ! $component instanceof CreateRecord || ! is_array($prefill) || $prefill === []) {
            return;
        }
        $this->done = true;
        try {
            $names = array_keys($component->form->getFlatFields(withHidden: true));
            $values = array_intersect_key(array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 500) : $v, $prefill), array_flip($names));
            if ($values !== []) {
                $component->form->fill([...(array) ($component->data ?? []), ...$values]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
