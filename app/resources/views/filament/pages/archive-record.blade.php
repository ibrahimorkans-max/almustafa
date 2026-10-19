{{-- One record, read-only (Office Desk screen). Params: $shown (ArchiveService::show) and $vehicle (VehicleRegister::details for a gate entry), or $error. --}}
@if (isset($error))
    <p class="text-sm text-danger-600">{{ $error }}</p>
@elseif ($vehicle !== null)
    <p class="mb-3 text-xs text-gray-500">Kept since {{ $shown['record']->archived_at?->timezone('Asia/Karachi')->format('d M Y') }} · read only, cannot be printed or downloaded.</p>
    @include('filament.pages.vehicle-register-details', ['d' => $vehicle])
@else
    <div class="space-y-3 text-sm">
        <p class="text-xs text-gray-500">{{ $shown['label'] }} · kept since {{ $shown['record']->archived_at?->timezone('Asia/Karachi')->format('d M Y') }} · read only, cannot be printed or downloaded.</p>
        <table class="w-full">
            @foreach ($shown['fields'] as [$label, $value])
                <tr class="border-t border-gray-100 dark:border-white/5">
                    <td class="py-1 pr-4 align-top text-gray-500" style="white-space: nowrap;">{{ $label }}</td>
                    <td class="py-1 align-top font-medium">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif
