{{--
    Six boxes for the 6-digit sign-in code: typing moves to the next box, Backspace goes back, a pasted code
    fills them all, and the sixth digit sends it at once (no button needed). A wrong code empties the boxes
    for another try. The code goes into the form state (data.code) only when it is complete.
--}}
@php($statePath = $getStatePath())
<div
    x-data="{
        digits: ['', '', '', '', '', ''],
        sending: false,
        box(i) { return this.$refs['d' + i]; },
        clear() { this.digits = ['', '', '', '', '', '']; this.sending = false; this.$nextTick(() => this.box(0)?.focus()); },
        type(i, e) {
            const v = (e.target.value || '').replace(/\D/g, '');
            if (v.length > 1) { this.fill(v); return; }
            this.digits[i] = v;
            e.target.value = v;
            if (v && i < 5) this.box(i + 1).focus();
            this.done();
        },
        back(i, e) {
            if (! this.digits[i] && i > 0) { e.preventDefault(); this.digits[i - 1] = ''; this.box(i - 1).focus(); }
        },
        fill(text) {
            const v = (text || '').replace(/\D/g, '').slice(0, 6).split('');
            this.digits = [...v, ...Array(6 - v.length).fill('')];
            this.box(Math.min(v.length, 5)).focus();
            this.done();
        },
        done() {
            const code = this.digits.join('');
            if (code.length !== 6 || this.sending) return;
            this.sending = true;
            $wire.$set('{{ $statePath }}', code, false);
            // Signed in: the page moves on. Still here: the code was wrong, so the boxes empty for another try.
            $wire.authenticate().finally(() => this.clear());
        },
    }"
    x-init="$nextTick(() => box(0)?.focus())"
    class="space-y-3"
>
    <div class="flex justify-center gap-2 sm:gap-3" x-on:paste.prevent="fill($event.clipboardData.getData('text'))">
        @foreach (range(0, 5) as $i)
            <input
                x-ref="d{{ $i }}"
                x-bind:value="digits[{{ $i }}]"
                x-on:input="type({{ $i }}, $event)"
                x-on:keydown.backspace="back({{ $i }}, $event)"
                x-on:focus="$event.target.select()"
                x-bind:disabled="sending"
                type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                aria-label="Digit {{ $i + 1 }} of 6"
                class="h-14 w-11 rounded-xl border-2 border-gray-300 bg-white text-center text-2xl font-bold text-gray-900 shadow-sm outline-none transition focus:border-primary-600 focus:ring-4 focus:ring-primary-600/15 disabled:opacity-60 sm:h-16 sm:w-12 dark:border-white/20 dark:bg-white/5 dark:text-white"
            >
        @endforeach
    </div>
    <p class="text-center text-xs text-gray-500 dark:text-gray-400" x-show="! sending">The code is checked as soon as the sixth digit is typed.</p>
    <p class="text-center text-xs font-medium text-primary-600 dark:text-primary-400" x-show="sending" x-cloak>Checking…</p>
</div>
