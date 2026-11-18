{{-- Recommendation: a login without a confirmed e-mail cannot prove a new network or device by itself, nor reset a
     forgotten password; the person is reminded to add it (My security e-mail). Not on the sign-in check screens. --}}
@php
    $user = \Filament\Facades\Filament::auth()->user();
    $route = request()->route()?->getName();
    $show = $user instanceof \App\Models\LoginAccount && ! $user->is_machine && ! $user->needsFirstSignIn()
        && (blank($user->email) || $user->email_verified_at === null)
        && ! \App\Domain\Auth\NewNetwork::isHeld()
        && ! in_array($route, ['filament.admin.pages.security-email', 'filament.admin.pages.new-network', 'filament.admin.pages.first-sign-in'], true);
@endphp
@if ($show)
    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-sm dark:border-primary-500/30 dark:bg-primary-500/10"
         x-data="{ hide: sessionStorage.getItem('rm-email-tip') === '1' }" x-show="! hide" role="note">
        <x-filament::icon icon="heroicon-o-envelope" class="h-5 w-5 text-primary-600" />
        <span class="min-w-0 flex-1">
            <strong>{{ blank($user->email) ? __('Recommended: add your e-mail.') : __('Recommended: confirm your e-mail.') }}</strong>
            {{ __('With it you can sign in from a new network or device by a code, and reset a forgotten password.') }}
        </span>
        <x-filament::button size="sm" tag="a" :href="\App\Filament\Pages\SecurityEmailPage::getUrl()">{{ blank($user->email) ? __('Add e-mail') : __('Confirm e-mail') }}</x-filament::button>
        <button type="button" class="text-xs text-gray-500 underline" x-on:click="hide = true; try { sessionStorage.setItem('rm-email-tip', '1') } catch (e) {}">{{ __('Later') }}</button>
    </div>
@endif
