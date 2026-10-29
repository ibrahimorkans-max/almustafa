{{-- SaaS: which records the customer's plan does not show (Platform → Data visibility). Nothing is deleted. --}}
@php($notices = \App\Domain\Saas\DataVisibility::notices())
@if ($notices !== [])
    <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 px-4 py-2 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
        @foreach ($notices as $line)
            <div>{{ $line }}</div>
        @endforeach
    </div>
@endif
