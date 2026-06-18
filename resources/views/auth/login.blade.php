{{--
    Authentik-only login page. Used only when the extension's "Authentik-only
    Login Page" setting is enabled, in which case the extension prepends its view
    path so this overrides the active theme's auth/login view. The theme itself is
    untouched, so the surrounding layout and assets are unaffected.

    This is the App\Livewire\Auth\Login component's view, so wire:model / wire:submit
    bind to that component. The native email/password form is shown only for
    admin break-glass, reachable at /login/local.
--}}
<div class="mx-auto flex flex-col gap-2 mt-4 px-6 sm:px-14 pb-10 bg-primary-800 rounded-md xl:max-w-[40%] w-full">
    <div class="flex flex-col items-center my-14">
        <x-logo class="h-10" />
        <h1 class="text-2xl text-center mt-6">{{ __('auth.sign_in_title') }}</h1>
    </div>

    {{-- Primary (and only) login path: Authentik SSO, injected by this extension. --}}
    {!! hook('auth.login') !!}

    @if (session('authentik_break_glass'))
        {{-- Break-glass admin login (native email/password), reached via /login/local. --}}
        <form class="flex flex-col gap-2 mt-6 pt-6 border-t border-primary-700" wire:submit="submit" id="login">
            <p class="text-xs text-center text-primary-300">{{ __('Recovery login') }}</p>
            <x-form.input name="email" type="email" :label="__('general.input.email')"
                :placeholder="__('general.input.email_placeholder')" wire:model="email" hideRequiredIndicator required
                autocomplete="email" />
            <x-form.input name="password" type="password" :label="__('general.input.password')"
                :placeholder="__('general.input.password_placeholder')" required hideRequiredIndicator
                wire:model="password" autocomplete="current-password" />
            <div class="flex flex-row">
                <x-form.checkbox name="remember" label="Remember me" wire:model="remember" />
                <a class="text-sm text-secondary-500 text-secondary hover:underline ml-auto"
                    href="{{ route('password.request') }}">{{ __('auth.forgot_password') }}</a>
            </div>
            <x-captcha :form="'login'" />
            <x-button.primary class="w-full" type="submit">{{ __('auth.sign_in') }}</x-button.primary>
        </form>
    @endif
</div>
