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

When **embedded mode** is enabled — the default, so it is an opt-out — the storefront acts as the embedded commerce backend for a Laioutr-rendered frontend:

- **Lockdown** — every storefront route except the cart, checkout, account, plugin session flows, and storefront widget/AJAX fragments (`/widgets/*` paths and `widgets.*` routes such as product quick view) redirects to the cart. Add exceptions (for example a payment plugin's return route) under **Additional allowed routes**, one route name per line.
- **Hidden chrome** — the storefront header, navigation, footer, and the built-in cookie-consent bar are not rendered; Laioutr provides them and owns consent in the frame.
- **Bridge** — a small static script (`Resources/public/laioutr-embed.js`) is loaded and talks to the Laioutr parent frame over `postMessage`.

Embedded mode is a per-sales-channel setting. **Installing _or updating_ the plugin locks the storefront down immediately on every channel where the setting is on** — the default also applies to an existing install the first time it updates onto this version. Disable it on any channel that should keep the full storefront. Run `bin/console assets:install` after installing or updating the plugin so `laioutr-embed.js` is published to `public/bundles/laioutrconnector/`. To browse the raw storefront during development, turn it off:

```bash
bin/console system:config:set -j LaioutrConnector.config.embeddedModeEnabled false
```

The `-j` flag stores a real JSON boolean. Without it the CLI stores the string `"false"`, which `getBool()` and the Twig `config()` function both read as truthy — leaving embedded mode enabled. The Administration toggle stores booleans correctly, so this only matters when setting the flag from the CLI.

### Bridge message contract

Every message uses the envelope `{ source: 'laioutr-shopware', version: 1, type, payload }`. On load the bridge posts `laioutr:ready` to `*`, then buffers data-bearing messages until the parent replies with `laioutr:init`; that reply's `event.origin` — validated against the allowed callback domains — becomes the pinned target for every later message.

| Direction | `type` | `payload` |
| --- | --- | --- |
| shop → parent | `laioutr:ready` | `{}` |
| shop → parent | `laioutr:resize` | `{ height }` |
| shop → parent | `laioutr:page-loaded` | `{ path, route, navigationId, salesChannelId }` |
| shop → parent | `laioutr:checkout-finish` | `{ orderId }` |
| shop → parent | `laioutr:pw-recovery` | `{}` |
| shop → parent | `laioutr:auth-changed` | `{ from, code? }` |
| parent → shop | `laioutr:init` | `{}` (its origin becomes the pinned target) |

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

Static analysis (`composer phpstan`) resolves against the Shopware root and runs in CI through Shopware's reusable PHPStan workflow.

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
