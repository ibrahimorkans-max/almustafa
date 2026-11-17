@if (config('ricemill.auth.password_reset', true))
    {{-- Only the SaaS owner, Owners and Super Admins can use it (App\Domain\Auth\PasswordReset). --}}
    <p class="text-center text-sm">
        <a href="{{ route('filament.admin.auth.forgot-password') }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ __('Forgot your password?') }}</a>
        <span class="block text-xs text-gray-500">{{ __('Owners and Super Admins only') }}</span>
    </p>
@endif
