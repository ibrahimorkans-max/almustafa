@props([
    'model' => 'otp',       // the Livewire property the code is put in
    'submit' => null,       // the Livewire method called when all six are typed (null: the form's own button sends it)
    'length' => 6,
    'label' => null,
])
{{--
    The 6-box code (App\Domain\Auth\EmailCode): auto-advance, paste, backspace; when the last box is filled the row
    "locks in" and is sent. The page answers with the browser events rmv-otp-invalid (wave shake, red) or
    rmv-otp-valid (boxes fold away to ticks). Logic: rmvOtp (filament/hooks/verify-motion).
--}}
<div x-data="rmvOtp({ length: {{ (int) $length }}, model: @js($model), submit: @js($submit) })"
     x-on:rmv-otp-invalid.window="invalid()" x-on:rmv-otp-valid.window="valid()" wire:ignore {{ $attributes }}>
    <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">{{ $label ?? __('Code from the e-mail') }}</label>
    <div class="rmv-otp-row" x-ref="row" role="group" aria-label="{{ $label ?? __('Code from the e-mail') }}">
        <template x-for="i in length" :key="i">
            <div class="rmv-otp-cell" :style="`--i: ${i - 1}`" :class="{ 'is-hop': hop === i - 1 }">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="dark:text-white"
                    :autocomplete="i === 1 ? 'one-time-code' : 'off'" :aria-label="`{{ __('Digit') }} ${i} / ${length}`"
                    :value="digits[i - 1]" x-on:input="type(i - 1, $event)" x-on:keydown="key(i - 1, $event)"
                    x-on:paste.prevent="paste($event)" x-on:focus="$event.target.select()">
                <span class="rmv-otp-check" aria-hidden="true">✓</span>
            </div>
        </template>
    </div>
    <p class="sr-only" aria-live="polite" x-text="said"></p>
</div>
