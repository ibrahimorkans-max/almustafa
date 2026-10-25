{{-- MillNexa on phones and in the browser: home-screen icon, app name and colour. --}}
<link rel="apple-touch-icon" href="{{ asset(config('ricemill.product.apple_touch')) }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset(config('ricemill.product.icon_192')) }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="{{ config('ricemill.product.green') }}">
<meta name="application-name" content="{{ config('ricemill.product.name') }}">
<meta name="apple-mobile-web-app-title" content="{{ config('ricemill.product.name') }}">
