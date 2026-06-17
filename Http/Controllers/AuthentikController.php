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
use SocialiteProviders\OpenIDConnect\Provider as OpenIDConnectProvider;
use Throwable;

class AuthentikController extends Controller
{
    /**
     * Begin the OIDC authorization-code flow.
     */
    public function redirect()
    {
        return $this->driver()
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    /**
     * Handle the OIDC callback: resolve the user (auto-provisioning on first
     * login, matched by email) and log them in.
     */
    public function callback(): RedirectResponse
    {
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
     * Build the Socialite OpenID Connect driver from the extension settings.
     */
    private function driver()
    {
        $settings = $this->settings();

        return Socialite::buildProvider(OpenIDConnectProvider::class, [
            'client_id' => $settings['client_id'] ?? null,
            'client_secret' => $settings['client_secret'] ?? null,
            'redirect' => '/oauth/authentik/callback',
            'base_url' => rtrim($settings['base_url'] ?? '', '/'),
        ]);
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
