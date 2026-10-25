@php
    $current = \App\Domain\Documents\PaperSize::current();
@endphp
{{-- Paper for every slip and report printed on this device (A4, A6, Letter, small roll). --}}
<div class="hidden items-center gap-1 sm:flex" x-data="{
        paper: @js($current ?? ''),
        saved: false,
        save() {
            fetch(@js(route('filament.admin.print-paper')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ paper: this.paper }),
                credentials: 'same-origin',
            }).then(() => { this.saved = true; setTimeout(() => this.saved = false, 1500) })
        },
    }">
    <x-filament::icon icon="heroicon-o-printer" class="h-5 w-5 text-gray-400" />
    <label class="sr-only" for="print-paper">Paper for printing</label>
    <select id="print-paper" x-model="paper" x-on:change="save()" title="Paper for printing slips and reports on this device"
        class="rounded-lg border-gray-300 py-1 pe-8 ps-2 text-xs dark:border-white/10 dark:bg-white/5">
        <option value="">Paper: as designed</option>
        @foreach (\App\Domain\Documents\PaperSize::OPTIONS as $key => $label)
            <option value="{{ $key }}">Paper: {{ $label }}</option>
        @endforeach
    </select>
    <span x-show="saved" x-cloak class="text-xs text-success-600">Saved</span>
</div>
