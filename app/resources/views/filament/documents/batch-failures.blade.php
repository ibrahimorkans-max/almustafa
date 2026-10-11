@php
    /** @var \App\Models\Documents\DocumentBatch $batch */
    $type = \App\Domain\Documents\Engine\DocumentTypes::get($batch->doc_type);
    $sources = $type->sourceClass()::query()->whereKey(array_keys($batch->failed ?? []))->get()->keyBy(fn ($m) => $m->getKey());
    $label = function ($source) {
        return match (true) {
            $source instanceof \App\Models\Hr\PayrollItem => $source->employee['code'].' '.$source->employee['name'],
            $source instanceof \App\Models\Hr\Employee => $source->code.' '.$source->name(),
            $source !== null && isset($source->employee_id) => \App\Models\Hr\Employee::query()->find($source->employee_id)?->code ?? '—',
            default => '—',
        };
    };
@endphp
<div class="space-y-2 text-sm">
    @if ($batch->error)
        <p class="rounded-lg bg-danger-50 p-2 text-danger-700 dark:bg-danger-950 dark:text-danger-300">The run stopped: {{ $batch->error }}</p>
    @endif
    @foreach ($batch->failed ?? [] as $id => $reason)
        <div class="border-b border-gray-100 pb-1 dark:border-gray-800">
            <span class="font-medium">{{ $label($sources->get($id)) }}</span>
            <span class="text-gray-600 dark:text-gray-300">— {{ $reason }}</span>
        </div>
    @endforeach
</div>
