{{-- The motion of the sign-in checks: styles, and the 6-box code's logic (registered before Alpine starts). --}}
<x-verify.styles />
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('rmvOtp', ({ length, model, submit }) => ({
            length,
            digits: Array(length).fill(''),
            hop: null,
            said: '',
            busy: false,
            inputs() { return this.$refs.row.querySelectorAll('input'); },
            reduced() { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; },
            code() { return this.digits.join(''); },
            // A typed digit: the box "hops" (180 ms, faster than conscious notice) and the next one takes focus.
            type(i, e) {
                const v = (e.target.value || '').replace(/\D/g, '');
                if (v.length > 1) { this.fill(v, i); return; }
                this.digits[i] = v;
                e.target.value = v;
                if (! v) { return; }
                this.hop = i; setTimeout(() => { if (this.hop === i) this.hop = null; }, 200);
                // Focus moves at once (a fast typist never loses a digit); the hop is only seen, never waited for.
                if (i < this.length - 1) { this.inputs()[i + 1]?.focus(); }
                this.complete();
            },
            key(i, e) {
                if (e.key === 'Backspace' && ! this.digits[i] && i > 0) { e.preventDefault(); this.digits[i - 1] = ''; this.inputs()[i - 1].value = ''; this.inputs()[i - 1].focus(); }
                if (e.key === 'ArrowLeft' && i > 0) { this.inputs()[i - 1].focus(); }
                if (e.key === 'ArrowRight' && i < this.length - 1) { this.inputs()[i + 1].focus(); }
                if (e.key === 'Enter' && this.code().length === this.length) { e.preventDefault(); this.send(); }
            },
            paste(e) { this.fill((e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, ''), 0); },
            fill(v, from) {
                [...v.slice(0, this.length - from)].forEach((d, k) => { this.digits[from + k] = d; this.inputs()[from + k].value = d; });
                this.inputs()[Math.min(from + v.length, this.length - 1)]?.focus();
                this.complete();
            },
            // All six: the row "locks in" together (320 ms) — a felt "sealed" — and is sent.
            complete() {
                if (this.code().length !== this.length || this.digits.some(d => d === '')) { return; }
                this.$wire.set(model, this.code(), false);
                if (! submit) { return; }
                this.$refs.row.classList.add('is-lock');
                setTimeout(() => { this.$refs.row.classList.remove('is-lock'); this.send(); }, this.reduced() ? 0 : 320);
            },
            send() {
                if (this.busy || ! submit) { return; }
                this.busy = true;
                this.said = @js(__('Checking the code…'));
                this.$wire.set(model, this.code(), false);
                this.$wire.call(submit).finally(() => { this.busy = false; });
            },
            // Wrong: a left-to-right wave shake with a red flash (400 ms), then empty boxes to try again.
            invalid() {
                const row = this.$refs.row;
                this.said = @js(__('Wrong code. Try again.'));
                row.classList.remove('is-error'); void row.offsetWidth; row.classList.add('is-error');
                setTimeout(() => {
                    row.classList.remove('is-error');
                    this.digits = Array(this.length).fill('');
                    this.inputs().forEach(el => el.value = '');
                    this.inputs()[0]?.focus();
                }, this.reduced() ? 0 : 520);
            },
            // Right: the boxes fold down one after another (40 ms) and reveal a tick each.
            valid() { this.said = @js(__('Code accepted.')); this.$refs.row.classList.add('is-valid'); },
        }));
    });
</script>

{{-- The number pick (x-verify.pick-number). --}}
<style>
    .rmv-pick { animation: rmv-pick-in .45s cubic-bezier(0.16, 1, 0.3, 1) both; animation-delay: calc(var(--i, 0) * 70ms); }
    @keyframes rmv-pick-in { from { opacity: 0; transform: translateY(10px) scale(.92); } }
    @media (prefers-reduced-motion: reduce) { .rmv-pick { animation: none !important; } }
</style>
