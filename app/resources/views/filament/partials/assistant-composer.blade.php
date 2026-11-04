{{--
    The quick buttons and the box (App\Livewire\Concerns\TalksToAssistant). A quick button writes a message with
    blanks (____) into the box and selects the first; Tab goes to the next blank. While the cursor is on a line,
    the data that fits it shows above the box (the vehicles waiting, the sellers, the paddy…; typing narrows it):
    pressing one fills that blank and goes to the next. Enter sends a one-line message; a longer one is sent with
    Ctrl+Enter or the Send button (Enter makes a new line).
--}}
@php($quickForms = $this->quickForms())
<style>
    .mnx-pill { padding: .45rem 1rem; border-radius: 9999px; cursor: pointer; font-size: .85rem; font-weight: 500; line-height: 1.2;
        border: 1.5px solid transparent;
        background: linear-gradient(#fff, #fff) padding-box, linear-gradient(90deg, rgb(var(--primary-600)), #6366f1) border-box;
        transition: transform .12s, box-shadow .12s; }
    .dark .mnx-pill { background: linear-gradient(rgb(var(--gray-900)), rgb(var(--gray-900))) padding-box, linear-gradient(90deg, rgb(var(--primary-500)), #818cf8) border-box; }
    .mnx-pill span { background: linear-gradient(90deg, rgb(var(--primary-700)), #4f46e5); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .dark .mnx-pill span { background: linear-gradient(90deg, rgb(var(--primary-400)), #a5b4fc); -webkit-background-clip: text; background-clip: text; }
    .mnx-pill:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(79,70,229,.18); }
    .mnx-pill-sm { padding: .3rem .8rem; font-size: .78rem; flex: none; white-space: nowrap; }
    .mnx-suggest { padding: .5rem .6rem; border-radius: .75rem; background: #fff; border: 1px solid rgba(0,0,0,.08); box-shadow: 0 4px 14px rgba(0,0,0,.08); }
    .dark .mnx-suggest { background: rgb(var(--gray-900)); border-color: rgba(255,255,255,.1); }
    .mnx-suggest-head { font-size: .75rem; color: rgb(var(--gray-500)); margin-bottom: .35rem; }
    .mnx-suggest-head strong { color: rgb(var(--gray-800)); }
    .dark .mnx-suggest-head strong { color: rgb(var(--gray-100)); }
    .mnx-suggest-list { display: flex; flex-wrap: wrap; gap: .35rem; max-height: 8.5rem; overflow-y: auto; padding: 1px; }
    .mnx-suggest-list .mnx-pill-sm { white-space: normal; text-align: left; max-width: 100%; }
</style>
<div class="flex flex-col gap-2"
    x-data="{
        blank: '{{ \App\Domain\Assistant\QuickForms::BLANK }}',
        field: '',
        typed: '',
        grow(el) { el.rows = Math.min(8, Math.max(1, el.value.split('\n').length)); },
        pick(el) { this.grow(el); const i = el.value.indexOf(this.blank); el.focus(); if (i >= 0) { el.setSelectionRange(i, i + this.blank.length); } this.look(el); },
        next(el) { let i = el.value.indexOf(this.blank, el.selectionEnd); if (i < 0) { i = el.value.indexOf(this.blank); } if (i < 0) { return false; } el.setSelectionRange(i, i + this.blank.length); this.look(el); return true; },
        line(el) { const v = el.value, c = el.selectionStart; const s = v.lastIndexOf('\n', c - 1) + 1; let e = v.indexOf('\n', c); if (e < 0) { e = v.length; } return { s: s, e: e, text: v.slice(s, e) }; },
        look(el) { const l = this.line(el), k = l.text.indexOf(':'); if (k < 0) { this.field = ''; this.typed = ''; return; } this.field = l.text.slice(0, k).trim(); this.typed = l.text.slice(k + 1).replace(this.blank, '').trim().toLowerCase(); },
        get choices() { const all = (this.$wire.suggest || {})[this.field] || []; const t = this.typed; return (t === '' || all.some(o => o.value.toLowerCase() === t) ? all : all.filter(o => o.label.toLowerCase().includes(t))).slice(0, 60); },
        choose(o) {
            const el = this.$refs.box, l = this.line(el), k = l.text.indexOf(':');
            if (k < 0) { return; }
            const upto = el.value.slice(0, l.s) + l.text.slice(0, k + 1) + ' ' + o.value;
            el.value = upto + el.value.slice(l.e);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.focus();
            el.setSelectionRange(upto.length, upto.length);
            if (! this.next(el)) { this.look(el); }
        },
    }"
    x-on:assistant-template.window="setTimeout(() => pick($refs.box), 60)">
    @if ($quickForms !== [] && $tab === 'chat' && count($messages) > 1)
        <div class="mnx-quick" style="display:flex; gap:.4rem; overflow-x:auto; padding:2px 1px 3px; scrollbar-width:thin;">
            @foreach ($quickForms as $key => $q)
                <button type="button" class="mnx-pill mnx-pill-sm" wire:click="quick('{{ $key }}')"><span>{{ $q['button'] }}</span></button>
            @endforeach
        </div>
    @endif

    {{-- The data that fits the line the cursor is on. --}}
    <div class="mnx-suggest" x-show="choices.length > 0" x-cloak>
        <div class="mnx-suggest-head">Choose <strong x-text="field.replace(/\s*\(.*\)\s*$/, '')"></strong> · press one to fill the blank (type to narrow)</div>
        <div class="mnx-suggest-list">
            <template x-for="o in choices" :key="o.label">
                <button type="button" class="mnx-pill mnx-pill-sm" x-on:mousedown.prevent="choose(o)"><span x-text="o.label"></span></button>
            </template>
        </div>
    </div>

    <form wire:submit="send" style="display:flex; gap:.5rem; align-items:flex-end;">
        <textarea x-ref="box" wire:model="message" maxlength="1000" autocomplete="off" dir="auto"
            rows="{{ min(8, max(1, substr_count($message, "\n") + 1)) }}"
            x-on:input="grow($el); look($el)"
            x-on:click="look($el)" x-on:keyup="look($el)" x-on:focus="look($el)"
            x-on:keydown.tab="if (next($el)) { $event.preventDefault(); }"
            x-on:keydown.enter="if ($event.shiftKey) { return; } if ($event.ctrlKey || $event.metaKey || ! $el.value.includes('\n')) { $event.preventDefault(); $wire.send(); }"
            placeholder="{{ $this->aiEnabled() ? 'e.g. How much profit this month? · Register MNA-21-1234 for sauda 0001' : 'e.g. stock · profit this month · arrived MNA-21-1234 · help' }}"
            class="flex-1 rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
            style="resize:none; min-height:2.4rem; line-height:1.4;"></textarea>
        <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled" wire:target="send">Send</x-filament::button>
    </form>
</div>
