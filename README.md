# Paymenter Authentik OIDC Extension

Adds **Sign in with Authentik** (OpenID Connect) to [Paymenter](https://paymenter.org).
It registers a login button on the customer login page and handles the OIDC
authorization-code flow against an [Authentik](https://goauthentik.io) provider,
auto-provisioning users on first login (matched by email).

Built for [Nordly](https://nordly.gg) as part of an Authentik-as-IdP hub-and-spoke
SSO setup, but generic enough to work with any Authentik instance.

- Paymenter v1.5.x (Laravel 12, PHP 8.3, Filament 5)
- Uses `laravel/socialite` + `socialiteproviders/openid-connect` (proper OIDC discovery)
- Self-contained: no core file edits required

## How it works

| Concern | Mechanism |
| --- | --- |
| Login button | `hook('auth.login')` render hook in the login view (no theme override) |
| Routes | `/oauth/authentik` and `/oauth/authentik/callback`, registered from `boot()` |
| OIDC | `Socialite::buildProvider(OpenIDConnectProvider::class, …)` with discovery |
| Config | Extension settings UI (`getConfig()`), read at request time |
| Provisioning | Auto-create on first login, matched by email; random password; email pre-verified |
| 2FA | Honors `tfa_secret` — sends users with 2FA through Paymenter's `/2fa` gate |

## Installation

1. **Install the OIDC Socialite provider** in the Paymenter root:

   ```bash
   cd /var/www/paymenter
   composer require socialiteproviders/openid-connect
   ```

2. **Place the extension** at `extensions/Others/Authentik/` (clone or symlink this repo):

   ```bash
   git clone git@github.com:nordlyhost/paymenter-authentik-extension.git \
     /var/www/paymenter/extensions/Others/Authentik
   ```

3. **Enable + configure** in the admin panel under Extensions → Authentik:
   - **Authentik OIDC Base URL** — e.g. `https://auth.example.com/application/o/paymenter`
   - **Client ID** / **Client Secret** — from the Authentik provider
   - **Login Button Label** — optional (defaults to `Authentik`)

4. **Verify route precedence** (the extension routes must win over Paymenter's
   wildcard `/oauth/{provider}`):

   ```bash
   php artisan route:list | grep oauth/authentik
   # expect: oauth.authentik.redirect / oauth.authentik.callback (NOT oauth.handle)
   ```

   If the wildcard wins, change the path in `routes/web.php` to a non-colliding
   prefix and update the Authentik redirect URI to match.

## Authentik setup

Create an **OAuth2/OpenID Provider** and an **Application** linked to it:

- **Redirect URI:** `https://<your-paymenter-domain>/oauth/authentik/callback`
- **Scopes:** `openid`, `profile`, `email`
- **Application slug:** e.g. `paymenter`
- **Discovery URL:** `https://<authentik-domain>/application/o/<slug>/.well-known/openid-configuration`

The **Base URL** in the extension config is the discovery URL without the
`/.well-known/openid-configuration` suffix.

## Notes / roadmap

- **v1 matches users by email only** (mirrors Paymenter's native Discord/Google
  flow). A user who already exists with the same email is linked automatically.
  Subject-ID-based linking (an `external_id` column) is a possible future hardening.
- Auto-creation trusts the IdP-asserted email as verified.

## License

[GPL-3.0](LICENSE), matching Paymenter.
