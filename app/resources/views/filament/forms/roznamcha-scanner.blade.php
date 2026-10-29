{{--
    Scanner. On the mill server with the mill's network scanner set (Administration → Integrations), "Scan now" reads
    the page on the scanner's glass. Otherwise: scan to a file with the scanner's own program and choose it below.
--}}
@php($scanned = filled($this->data['scanned_file_id'] ?? null))
@php($address = \App\Domain\Accounts\NetworkScanner::available() ? \App\Domain\Accounts\NetworkScanner::address(app(\App\Domain\Tenancy\TenantContext::class)->entityId()) : null)
<div class="rounded-lg border border-gray-200 p-4 text-sm dark:border-white/10">
    @if ($scanned)
        <p class="font-medium text-success-600">Page scanned. Save the page to read it, or scan again.</p>
        <x-filament::button type="button" color="gray" size="sm" class="mt-2" icon="heroicon-o-arrow-path" wire:click="scanNow" wire:loading.attr="disabled">Scan again</x-filament::button>
    @elseif ($address)
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="button" icon="heroicon-o-printer" wire:click="scanNow" wire:loading.attr="disabled" wire:target="scanNow">Scan now</x-filament::button>
            <span class="text-gray-500" wire:loading.remove wire:target="scanNow">Put the page face down on the scanner's glass, then press Scan now ({{ $address }}).</span>
            <span class="text-primary-600" wire:loading wire:target="scanNow">Scanning… (up to a minute)</span>
        </div>
    @else
        <p class="text-gray-500">No scanner is connected to this server. Scan the page with the scanner's own program (Windows Fax and Scan, the scanner's app) as a picture or PDF, and choose the file below.
            @if (\App\Domain\Accounts\NetworkScanner::available())
                Or set the scanner's address under Administration → Integrations to scan from here.
            @endif
        </p>
    @endif
</div>
