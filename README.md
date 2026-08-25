# Laioutr Connector

[![CI](https://github.com/laioutr/shopware-laioutr-connector/actions/workflows/ci.yml/badge.svg)](https://github.com/laioutr/shopware-laioutr-connector/actions/workflows/ci.yml)

Standalone Shopware 6 plugin for integrating Shopware with Laioutr.

## Requirements

- PHP 8.2–8.5
- Shopware 6.6–6.7
- Shopware Storefront

## Installation

Install the package in a Shopware project and activate the technical plugin `LaioutrConnector`:

```bash
composer require laioutr/shopware-connector
bin/console plugin:refresh
bin/console plugin:install --activate --clearCache LaioutrConnector
```

For filesystem development, place or mount this repository at `custom/plugins/LaioutrConnector`, then run the same plugin commands.

## Configuration

Configure **Extensions → My extensions → Laioutr Connector** and enter one allowed callback domain per line. `*` wildcards are supported:

```text
laioutr.example.com
*.preview.laioutr.example.com
```

`*.example.com` matches subdomains but not `example.com` itself. An empty configuration rejects every callback.

For local development:

```bash
bin/console system:config:set \
    LaioutrConnector.config.callbackDomainWildcard localhost
```

## Embedded storefront mode

Two independent per-sales-channel settings, both on by default.

**Embedded mode** (`embeddedModeEnabled`) makes the storefront usable as the commerce backend for a Laioutr-rendered frontend. It is required for the integration to work:

- **Hidden chrome** — the storefront header, navigation, footer, and the built-in cookie-consent bar are not rendered; Laioutr provides them and owns consent in the frame.
- **Bridge** — a small static script (`Resources/public/laioutr-embed.js`) is loaded and talks to the Laioutr parent frame over `postMessage`.
- **Framing** — `X-Frame-Options` is not sent, so the storefront can be embedded.
- **Top-level order submit** — the confirm form targets the top-level window rather than the frame, so redirect-based payment providers are never framed. See `POST /laioutr/checkout-order`.
- **Return trip** — a checkout that left the frame is returned to Laioutr: a completed order redirects to the configured **Order success page** with `?order=`, and a failed or cancelled payment redirects to the **Checkout page** with `?retry-order=` and `&error-code=`. Un-embedded, only the success redirect applies; the storefront handles a retry itself. Both URLs must be on an allowed callback domain, and a URL that is not is ignored rather than followed. A hit on the retry route carrying `laioutr-retry` is left alone — that is the frame opening the retry itself, and returning it would send it back to the page that opened it.

**Lockdown** (`lockdownEnabled`) answers a different question — who owns the content pages — and works **independently**: it redirects the pages Laioutr renders itself (home, listing, product detail, search, suggest, landing, standalone CMS pages and the wishlist) to the cart. **Everything else stays reachable**, including the routes payment plugins register. Add more under **Additional blocked routes**, one route name per line.

The two are independent in both directions. Lockdown without embedded mode is the right setting when Laioutr renders the content but sends shoppers to this storefront's own checkout on its own domain — the storefront keeps its chrome and stays un-framed, while stray hits on product or listing URLs still go to the cart. Embedded mode without lockdown is how you diagnose a payment method misbehaving in the frame, without un-framing the shop or restoring its header.

Upgrading from a version that had only `embeddedModeEnabled` carries that value into `lockdownEnabled` at every scope where it was set, so behaviour does not change until you change it.

**Installing _or updating_ the plugin applies both defaults immediately on every channel** — including an existing install the first time it updates onto this version. Run `bin/console assets:install` after installing or updating so `laioutr-embed.js` is published to `public/bundles/laioutrconnector/`. To browse the raw storefront during development:

```bash
bin/console system:config:set -j LaioutrConnector.config.lockdownEnabled false      # keep the frame, drop the route restriction
bin/console system:config:set -j LaioutrConnector.config.embeddedModeEnabled false  # leave embedded mode entirely
```

The `-j` flag stores a real JSON boolean. Without it the CLI stores the string `"false"`, which `getBool()` and the Twig `config()` function both read as truthy — leaving embedded mode enabled. The Administration toggle stores booleans correctly, so this only matters when setting the flag from the CLI.

### Bridge message contract

Every message uses the envelope `{ source: 'laioutr-shopware', version: 1, type, payload }`. On load the bridge posts `laioutr:ready` to `*`, then buffers data-bearing messages until the parent replies with `laioutr:init`; that reply's `event.origin` — validated against the allowed callback domains — becomes the pinned target for every later message.

| Direction | `type` | `payload` |
| --- | --- | --- |
| shop → parent | `laioutr:ready` | `{}` |
| shop → parent | `laioutr:resize` | `{ height }` |
| shop → parent | `laioutr:page-loaded` | `{ path, route, navigationId, salesChannelId, returnFallback }` |
| shop → parent | `laioutr:checkout-finish` | `{ orderId }` |
| shop → parent | `laioutr:pw-recovery` | `{}` |
| shop → parent | `laioutr:auth-changed` | `{ from, code? }` |
| parent → shop | `laioutr:init` | `{}` (its origin becomes the pinned target) |
| parent → shop | `laioutr:order-handoff` | `{ code }` |

`laioutr:order-handoff` carries a single-use code the confirm form submits to `POST /laioutr/checkout-order`; the parent re-posts it while the shopper is on the confirm page, since codes expire in 60 seconds. Until one arrives the form is left submitting in-frame.

`laioutr:auth-changed` fires in embedded mode after a storefront login (`from` = the login route, `code` present) or logout (`from` = the logout route, no `code`). `code` is a single-use handoff code the parent redeems server-to-server at `POST /store-api/laioutr/session-adopt` for the customer-bound context token; the token never enters the browser.

## Session endpoints

The Shopware context token is never placed in a browser URL. Connecting a session is a two-step exchange: laioutr's backend mints a short-lived, single-use code server-to-server, then redirects the browser to redeem it.

### `POST /store-api/laioutr/session-handoff`

Mints a handoff code. Called server-to-server by the laioutr backend, which holds the `sw-context-token`.

Required headers:

| Header | Meaning |
| --- | --- |
| `sw-access-key` | Sales-channel access key |
| `sw-context-token` | Context token to hand off |

JSON body:

```json
{
  "login-success-callback": "https://<allowed-domain>/login",
  "logout-success-callback": "https://<allowed-domain>/logout",
  "redirect-route": "frontend.checkout.cart.page"
}
```

`login-success-callback` and `logout-success-callback` are validated against the allowed callback domains configuration. Response:

```json
{ "code": "<opaque single-use code>" }
```

The code is valid for 60 seconds and can be redeemed once.

### `POST /store-api/laioutr/session-adopt`

Redeems a handoff code for its context token. Called server-to-server by the laioutr backend after it receives a `laioutr:auth-changed` message with a `code`.

Required headers:

| Header | Meaning |
| --- | --- |
| `sw-access-key` | Sales-channel access key |

JSON body:

```json
{ "code": "<single-use code>" }
```

Response:

```json
{ "context-token": "<customer-bound context token>" }
```

The code is single-use, expires in 60 seconds, and must have been issued for the requesting sales channel. Invalid, expired, already-redeemed, or wrong-sales-channel codes return `400`.

### `GET /laioutr/connect-session`

Redeems a handoff code and redirects to a local Shopware route.

Required query parameters:

| Parameter | Meaning |
| --- | --- |
| `code` | Single-use code returned by `POST /store-api/laioutr/session-handoff` |

Example:

```text
/laioutr/connect-session?code=…
```

The plugin redeems the code, verifies it was issued for the requesting sales channel, installs the context into the storefront session, and regenerates the session id before redirecting to the stored route so the shopper lands there with their basket.

Callback redirects append only the URL-encoded `from` route. The Shopware context token is never included in the callback payload.

### `POST /laioutr/checkout-order`

Installs a session from a single-use handoff code, then forwards the submission to Shopware's order route with a `307` so its method and body survive.

| Parameter | Description |
| --- | --- |
| `code` | Single-use code returned by `POST /store-api/laioutr/session-handoff`, carried as a form field |

In embedded mode the bridge script retargets the confirm form at the top-level window once Laioutr posts it a code, because redirect-based payment providers refuse to render in a frame and cannot navigate back out of one. That top-level request carries no storefront session when Laioutr and the storefront sit on different registrable domains, which is what the code establishes. Laioutr re-mints while the shopper is on the confirm page, since codes expire in 60 seconds.

### `GET /laioutr/cookie-bridge`

Redirects to an allowed external URL so the browser can establish the Shopware session in an embedded flow. Here, unlike connect-session, `redirect-route` is the complete external URL.

```text
/laioutr/cookie-bridge?redirect-route=https%3A%2F%2Flaioutr.example.com%2Fcallback
```

## Embedded storefront prerequisite

The plugin removes `X-Frame-Options` globally because embedding Shopware is required for the integration to function. Deployments must restrict embedding with an appropriate Content Security Policy such as `frame-ancestors` at the application or reverse-proxy layer. With embedded mode enabled, the plugin also stops rendering the storefront header and footer; the `frame-ancestors` policy remains the boundary that controls which origins may embed the shop.

Cross-site sessions also generally require HTTPS, secure cookies, and:

```yaml
framework:
    session:
        cookie_samesite: none
        cookie_secure: true
```

Browser third-party-cookie policies can still prevent embedded sessions.

## Development

Use a separate Shopware project for local development. From this repository, create one with Shopware CLI and clone Shopware's demo-data plugin into it:

```bash
shopware-cli project create shopware-dev 6.7.12.1 --docker
git clone https://github.com/shopware/SwagPlatformDemoData.git \
    shopware-dev/custom/plugins/SwagPlatformDemoData
```

Mount this repository into the project with a Compose override. A bind mount is required because the Docker environment only mounts the `shopware-dev/` project directory, so a symlink to this repository would not resolve inside the container:

```bash
cat > shopware-dev/compose.override.yaml <<'YAML'
services:
    web:
        volumes:
            - ..:/var/www/html/custom/plugins/LaioutrConnector
YAML
```

Then start the environment:

```bash
cd shopware-dev
shopware-cli project dev
```

The first `project dev` run installs Shopware. In another terminal, refresh the extension list and activate both plugins:

```bash
cd shopware-dev
shopware-cli project console plugin:refresh
shopware-cli project console plugin:install --activate LaioutrConnector
shopware-cli project console plugin:install --activate SwagPlatformDemoData
```

The demo-data plugin imports sample data during activation and may overwrite existing data. Use it only in development. The storefront is available at <http://127.0.0.1:8000> and the Administration at <http://127.0.0.1:8000/admin> (`admin` / `shopware`).

The generated project ships without test tooling. Install Shopware's dev tools once so PHPUnit is available at the Shopware root:

```bash
docker compose exec web composer require --dev shopware/dev-tools
```

Then run the plugin's test suite from that Shopware installation:

```bash
docker compose exec web composer \
    --working-dir custom/plugins/LaioutrConnector phpunit
```

Run formatting and compatibility checks from the plugin repository (Docker required):

```bash
composer check
```

Static analysis (`composer phpstan`) runs at level 9 over `src`. `phpstan-bootstrap.php` walks upwards until it finds a Composer autoloader that provides Shopware, so the same `phpstan.neon.dist` works from a Shopware project, from a Shopware monorepo checkout (Shopware's reusable PHPStan workflow), and standalone with the plugin's own vendor directory (`shopware-cli extension validate --full`).

Those compatibility checks resolve Shopware into a throwaway directory, where `shopware/core` pins `dompdf/dompdf` 3.1.4 — a version with published advisories that Composer's advisory gate refuses to install. `config.policy.advisories.ignore` in `composer.json` exempts that single package. Composer only honours `config` of the root package, so this applies to work in this repository and never to a shop that installs the plugin.

CI independently provisions clean Shopware installations for every supported release line with Shopware's reusable GitHub Actions workflow.

No Administration or Storefront **build** is required: the plugin ships Twig template overrides and one static JavaScript asset (`src/Resources/public/laioutr-embed.js`, served via `asset()`), with no webpack, Vite, or SCSS entrypoint — run `bin/console assets:install` to publish the asset. The embedded-mode Twig overrides and bridge are verified against a running dev shop with a theme assigned; Shopware's PHPUnit harness installs without a theme, so plugin storefront template overrides do not resolve under it and are not asserted there.

## Releases

Releases are prepared by [Release Please](https://github.com/googleapis/release-please). Use Conventional Commit subjects so the release PR can determine the next semantic version:

- `fix:` creates a patch release.
- `feat:` creates a minor release.
- `feat!:` or a `BREAKING CHANGE:` footer creates a major release.

Release Please maintains a reviewable release PR that updates `composer.json` and `CHANGELOG.md`. Merging that PR creates a `vX.Y.Z` tag and GitHub Release. The release workflow then attaches `LaioutrConnector-vX.Y.Z.zip` and its SHA-256 checksum and generates a GitHub artifact attestation for the ZIP.

Release Please pull requests created with the repository `GITHUB_TOKEN` do not automatically run pull-request workflows. Before merging one, run the **CI** workflow manually for its branch. Repository Actions settings must permit GitHub Actions to create and approve pull requests.

### Packagist

The package is published on public Packagist as [`laioutr/shopware-connector`](https://packagist.org/packages/laioutr/shopware-connector), fed by the public GitHub repository through the Packagist GitHub integration. Packagist indexes new release tags automatically; no Packagist token or ZIP upload is needed in GitHub Actions.

### Shopware Store

Store publishing is disabled until all one-time prerequisites are configured:

1. Create the `LaioutrConnector` listing in the Shopware Account.
2. Create Extension Partner client credentials.
3. Create and protect the `shopware-store` GitHub environment.
4. Add environment secrets `SHOPWARE_CLI_ACCOUNT_CLIENT_ID` and `SHOPWARE_CLI_ACCOUNT_CLIENT_SECRET`.
5. Set repository variable `SHOPWARE_STORE_PUBLISH_ENABLED` to `true` last.

The Store job downloads and verifies the exact ZIP already attached to the GitHub Release. If the variable is absent or not `true`, Store publishing is skipped without affecting the GitHub Release or Packagist.

The **Release** workflow can also be dispatched with an existing `vX.Y.Z` tag to rebuild and replace missing GitHub Release assets. Recovery runs never publish to the Store; Store upload occurs only in the automatic release run and remains subject to the protected environment and gate.

See [`docs/hardening.md`](docs/hardening.md) for protocol and deployment work intentionally deferred from the extraction.
