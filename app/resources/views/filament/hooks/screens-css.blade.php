{{-- After Filament's stylesheet, so a screen's responsive classes (sm:grid-cols-2 …) take effect. Rebuild: npm run build:screens --}}
@php($screensCss = public_path('css/millnexa/screens.css'))
@if (is_file($screensCss))
    <link rel="stylesheet" href="{{ asset('css/millnexa/screens.css') }}?v={{ filemtime($screensCss) }}">
@endif
