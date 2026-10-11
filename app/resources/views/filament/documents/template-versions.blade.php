<div class="space-y-3 text-sm">
    @foreach ($versions as $v)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="flex justify-between font-semibold">
                <span>Version {{ $v->version_no }}</span>
                <span class="text-xs text-gray-500">{{ $v->created_at->timezone('Asia/Karachi')->format('d M Y H:i') }}</span>
            </div>
            <p class="text-gray-600 dark:text-gray-300">{{ $v->note }}</p>
            <p class="mt-1 text-xs text-gray-500">
                Shown by default: {{ collect($v->settings['toggles'] ?? [])->filter()->keys()->map(fn ($k) => \App\Domain\Documents\Engine\TemplateRepository::TOGGLES[$k] ?? $k)->implode(', ') ?: 'nothing extra' }}
                @if ($v->settings['header_text'] ?? '') · Header: “{{ $v->settings['header_text'] }}” @endif
                @if ($v->settings['accent'] ?? null) · Colour {{ $v->settings['accent'] }} @endif
            </p>
        </div>
    @endforeach
</div>
