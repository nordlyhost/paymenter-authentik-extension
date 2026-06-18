<?php

namespace Paymenter\Extensions\Others\Authentik\Http\Controllers;

use App\Actions\Auth\Login;
use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use SocialiteProviders\Manager\Config;
use Throwable;

class AuthentikController extends Controller
{
    /**
     * Begin the OIDC authorization-code flow.
     */
    public function redirect()
    {
        // setScopes() replaces the provider's defaults (which include
        // goauthentik.io/api); scopes() would merge and over-request.
        $driver = $this->driver()->setScopes(['openid', 'profile', 'email']);

        // Optional OIDC prompt (e.g. select_account / login) so users can confirm
        // or switch account instead of being silently re-logged-in by their
        // existing Authentik session.
        $prompt = $this->settings()['login_prompt'] ?? '';
        if ($prompt !== '') {
            $driver->with(['prompt' => $prompt]);
        }

        return $driver->redirect();
    }

    /**
     * Handle the OIDC callback: resolve the user (auto-provisioning on first
     * login, matched by email) and log them in.
     */
    public function callback(): RedirectResponse
    {
        $settings = $this->settings();

        try {
            $oauthUser = $this->driver()->user();
        } catch (Throwable $e) {
            Log::warning('Authentik OIDC callback failed: ' . $e->getMessage());

            return redirect()->route('login')->with('error', 'Authentik login failed. Please try again.');
        }

        $email = $oauthUser->getEmail();
        if (!$email) {
            return redirect()->route('login')->with('error', 'Authentik did not return an email address.');
        }

        // Authentik reports email_verified=false unless an email-verification flow
        // is configured, so this is opt-in. Authentik is a trusted IdP and the
        // admin owns the email, so by default the claim is accepted as-is.
        if (filter_var($settings['require_verified_email'] ?? false, FILTER_VALIDATE_BOOL)
            && ($oauthUser->getRaw()['email_verified'] ?? false) !== true) {
            return redirect()->route('login')->with('error', 'Your Authentik email address is not verified.');
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = $this->provisionUser($oauthUser, $email);
        }

        // Mirror Paymenter's native social login 2FA gate.
        if ($user->tfa_secret) {
            Session::put('2fa', [
                'user_id' => $user->id,
                'remember' => true,
                'expires' => now()->addMinutes(5),
            ]);

            return redirect()->route('2fa');
        }

        (new Login)->execute($user, true);

        return redirect()->route('home');
    }

    /**
     * Build the Socialite Authentik driver from the extension settings.
     *
     * Built manually (not via the SocialiteWasCalled event), so the provider's
     * additional `base_url` config key is injected with setConfig(). base_url is
     * the Authentik instance root (e.g. https://auth.example.com); the provider
     * appends /application/o/{authorize,token,userinfo}/ itself.
     */
    private function driver()
    {
        $settings = $this->settings();

        $clientId = $settings['client_id'] ?? null;
        $clientSecret = $settings['client_secret'] ?? null;
        $baseUrl = rtrim($settings['base_url'] ?? '', '/');
        $redirect = '/oauth/authentik/callback';

        $driver = Socialite::buildProvider(AuthentikProvider::class, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect' => $redirect,
        ]);

        $driver->setConfig(new Config($clientId, $clientSecret, $redirect, [
            'base_url' => $baseUrl,
        ]));

        return $driver;
    }

    /**
     * Load the enabled Authentik extension settings as a key => value map.
     */
    private function settings(): array
    {
        $extension = Extension::where('extension', 'Authentik')
            ->where('enabled', true)
            ->first();

        if (!$extension) {
            abort(404);
        }

        return $extension->settings->pluck('value', 'key')->toArray();
    }

    /**
     * Auto-provision a new user from the OIDC claims. The IdP has already
     * authenticated the user, so we trust the verified email and set a random
     * password (login is delegated to Authentik).
     */
    private function provisionUser($oauthUser, string $email): User
    {
        [$first, $last] = $this->resolveName($oauthUser);

        return User::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Derive first/last name from OIDC claims, falling back to the display name.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveName($oauthUser): array
    {
        $raw = method_exists($oauthUser, 'getRaw') ? $oauthUser->getRaw() : (array) ($oauthUser->user ?? []);

        $first = $raw['given_name'] ?? null;
        $last = $raw['family_name'] ?? null;

        if (!$first) {
            $name = trim((string) ($oauthUser->getName() ?? $raw['name'] ?? $oauthUser->getNickname() ?? ''));
            if ($name !== '') {
                $parts = preg_split('/\s+/', $name, 2);
                $first = $parts[0];
                $last = $last ?? ($parts[1] ?? '');
            }
        }

        return [$first ?: 'Authentik', $last ?: 'User'];
    }
}
