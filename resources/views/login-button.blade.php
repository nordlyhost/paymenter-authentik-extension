{{-- Injected into the login page via Paymenter's hook('auth.login') render hook. --}}
<div class="flex flex-col items-center mt-4" data-authentik-login>
    <a href="{{ $url }}"
        class="flex items-center justify-center w-full px-4 h-10 border border-neutral rounded-md text-primary-100 hover:bg-primary-700 transition-colors">
        <svg class="size-5 mr-2 text-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 3l7 3v5c0 4.4-3 7.4-7 9-4-1.6-7-4.6-7-9V6l7-3z" />
            <path d="M9 12l2 2 4-4" />
        </svg>
        {{ $label }}
    </a>
</div>
