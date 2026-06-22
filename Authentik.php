<?php

namespace Paymenter\Extensions\Others\Authentik;

use App\Classes\Extension\Extension;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;

/**
 * Authentik OIDC login for Paymenter.
 *
 * Adds a "Sign in with Authentik" button to the login page and handles the
 * OpenID Connect authorization-code flow against an Authentik provider,
 * auto-provisioning users on first login (matched by email).
 *
 * @link https://docs.paymenter.org/development/extensions
 */
class Authentik extends Extension
{
    /**
     * Configuration fields shown in the extension settings UI.
     *
     * @param  array  $values
     * @return array
     */
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'base_url',
                'label' => 'Authentik Base URL',
                'type' => 'text',
                'description' => 'Your Authentik instance root, e.g. https://auth.example.com '
                    . '(no trailing path). The OAuth endpoints are derived from this automatically.',
                'required' => true,
            ],
            [
                'name' => 'client_id',
                'label' => 'Client ID',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'client_secret',
                'label' => 'Client Secret',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'button_label',
                'label' => 'Login Button Label',
                'type' => 'text',
                'description' => 'Text shown on the login button.',
                'default' => 'Authentik',
                'required' => false,
            ],
            [
                'name' => 'require_verified_email',
                'label' => 'Require Verified Email',
                'type' => 'checkbox',
                'database_type' => 'boolean',
                'description' => 'Reject logins where Authentik reports the email as unverified. '
                    . 'Leave off unless you have configured an email-verification flow in Authentik '
                    . '(Authentik reports email_verified=false by default).',
                'default' => false,
                'required' => false,
            ],
            [
                'name' => 'force_sso_login',
                'label' => 'Authentik-only Login Page',
                'type' => 'checkbox',
                'database_type' => 'boolean',
                'description' => 'Replace the login page with an Authentik-only login, hiding the native '
                    . 'email/password form and other social providers. The native login stays reachable at '
                    . '/login/local for admin break-glass. Pairs well with disabling registration.',
                'default' => false,
                'required' => false,
            ],
            [
                'name' => 'login_prompt',
                'label' => 'Account Selection Prompt',
                'type' => 'select',
                'description' => 'Controls what Authentik shows when a user clicks the login button while already '
                    . 'signed in to Authentik. "Seamless" logs them straight back in. "Select account" shows a '
                    . 'confirm/switch screen (like Google). "Re-authenticate" forces a fresh login each time.',
                'default' => '',
                'required' => false,
                'options' => [
                    '' => 'Seamless (silent sign-in)',
                    'select_account' => 'Select account (confirm / switch)',
                    'login' => 'Re-authenticate (force login)',
                ],
            ],
        ];
    }

    /**
     * Booted on every request while the extension is enabled.
     */
    public function boot()
    {
        require __DIR__ . '/routes/web.php';

        View::addNamespace('authentik', __DIR__ . '/resources/views');

        // Capture config now so the deferred render closure doesn't rely on
        // backtrace-based config resolution at render time.
        $label = $this->config('button_label') ?: 'Authentik';
        $forceSso = filter_var($this->config('force_sso_login'), FILTER_VALIDATE_BOOL);

        // Inject Nordly brand colors and top glow into the client portal <head>.
        Event::listen('head', function () {
            return [
                'view' => '<style>' .
                    // Override Paymenter's default blue primary with Nordly green
                    ':root{--color-primary:142 36% 27%}' .
                    '.dark{--color-primary:142 28% 43%}' .
                    // Subtle top glow matching the marketing site
                    'body::before{content:"";position:fixed;top:0;left:0;right:0;height:400px;pointer-events:none;z-index:0;' .
                    'background:radial-gradient(60% 100% at 50% 0%,color-mix(in srgb,#2d5f3f 25%,transparent) 0%,transparent 100%)}' .
                    // Lockup already includes wordmark — hide the standalone app-name text
                    'nav a>span.font-bold,footer .flex-row>span.font-bold{display:none!important}' .
                    // Footer: match marketing site layout
                    // Transparent bg + ultra-thin border (no separate footer bg)
                    'footer{background:transparent!important;border-top:1px solid rgba(255,255,255,0.05)!important}' .
                    // Remove the huge my-12 margin on the inner container, replace with padding
                    'footer>.container{margin-top:0!important;margin-bottom:0!important;padding-top:2rem!important;padding-bottom:2rem!important}' .
                    // Left column: flip from flex-col to flex-row so logo+copyright are on one line
                    'footer .flex-col.gap-6{flex-direction:row!important;align-items:center!important;gap:0!important;flex:1!important}' .
                    // Copyright: push to right edge of left column, small + muted
                    'footer .flex-col.gap-6>div:last-child{margin-left:auto!important;padding-right:2rem;font-size:0.75rem!important;opacity:0.4}' .
                    // Logo in footer: h-7 matching marketing site, slight opacity
                    'footer img[alt="Nordly"]{height:1.75rem!important;width:auto;opacity:0.8}' .
                    // Hide "Powered by Paymenter" badge
                    'footer a[href="https://paymenter.org"]{display:none!important}' .
                    '</style>',
                'priority' => 100,
            ];
        });

        // Add cross-platform navigation links to the user account dropdown.
        Event::listen('navigation.account-dropdown', function () {
            return [
                [
                    'name' => 'Game Panel',
                    'url' => 'https://panel.nordly.gg',
                    'spa' => false,
                    'priority' => 5,
                ],
                [
                    'name' => 'nordly.gg',
                    'url' => 'https://nordly.gg',
                    'spa' => false,
                    'priority' => 6,
                ],
            ];
        });

        // Inject the login button via Paymenter's `hook('auth.login')` render hook.
        Event::listen('auth.login', function () use ($label) {
            return [
                'view' => view('authentik::login-button', [
                    'label' => $label,
                    'url' => route('oauth.authentik.redirect'),
                ])->render(),
                'priority' => 20,
            ];
        });

        // When configured, override the customer login view with an Authentik-only
        // page. Done by prepending this extension's view path (theme untouched, so
        // layout/assets are unaffected). Prepend after the app is fully booted so it
        // wins over the theme view paths, which are set during provider boot. If the
        // override never resolves, the normal theme login is shown — a safe fallback.
        if ($forceSso) {
            app()->booted(function () {
                app('view')->getFinder()->prependLocation(__DIR__ . '/resources/views');
            });
        }
    }
}
