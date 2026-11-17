@php
    $current = app()->getLocale();
    $languages = \App\Support\Locales::all();
@endphp
{{-- The language of the whole system: English, اردو, پښتو, سنڌي. Remembered for this person and this browser. --}}
<div class="flex items-center gap-1" x-data>
    <x-filament::icon icon="heroicon-o-language" class="h-5 w-5 text-gray-400" />
    <label class="sr-only" for="system-language">{{ __('Language') }}</label>
    <select id="system-language" title="{{ __('Language') }}"
        x-on:change="window.location.href = @js(url('/language')) + '/' + $event.target.value"
        class="rounded-lg border-gray-300 py-1 pe-8 ps-2 text-xs dark:border-white/10 dark:bg-white/5">
        @foreach ($languages as $code => $language)
            <option value="{{ $code }}" @selected($code === $current) lang="{{ $code }}">{{ $language['native'] }}</option>
        @endforeach
    </select>
</div>
