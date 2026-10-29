{{-- A list prints every row (an export: 'report.export'); one record prints as a document. --}}
@php($recordPage = is_subclass_of($pageClass ?? '', \Filament\Resources\Pages\ViewRecord::class) || is_subclass_of($pageClass ?? '', \Filament\Resources\Pages\EditRecord::class))
@if ($recordPage || \App\Filament\Support\ExportGuard::allowed())
<x-filament::button color="gray" icon="heroicon-o-printer" wire:click="printScreen" wire:loading.attr="disabled" wire:target="printScreen"
    tooltip="Print this screen as a PDF (a list prints every row that matches the search and filters)">
    Print
</x-filament::button>
@endif
