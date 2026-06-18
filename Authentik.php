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
