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

The plugin removes `X-Frame-Options` globally because embedding Shopware is required for the integration to function. Deployments must restrict embedding with an appropriate Content Security Policy such as `frame-ancestors` at the application or reverse-proxy layer.

Cross-site sessions also generally require HTTPS, secure cookies, and:

```yaml
framework:
    session:
        cookie_samesite: none
        cookie_secure: true
```

Browser third-party-cookie policies can still prevent embedded sessions.

## Development

Use a separate Shopware project for local development. Create one with Shopware CLI or use an existing installation, then link this repository into its plugin directory:

```bash
shopware-cli project create shopware-dev 6.6.10.20 --docker
ln -s "$(pwd)" shopware-dev/custom/plugins/LaioutrConnector
cd shopware-dev
docker compose up -d
docker compose exec web bin/console plugin:refresh
docker compose exec web bin/console plugin:install --activate LaioutrConnector
```

Run tests and static analysis from that Shopware installation:

```bash
docker compose exec web composer \
    --working-dir custom/plugins/LaioutrConnector phpunit
docker compose exec web composer \
    --working-dir custom/plugins/LaioutrConnector phpstan
```

Run formatting and compatibility checks from the plugin repository (Docker required):

```bash
composer check
```

CI independently provisions clean Shopware installations for every supported release line with Shopware's reusable GitHub Actions workflow.

No Administration or Storefront asset build is required because the plugin currently has no JavaScript, Twig, SCSS, or asset entrypoint.

## Releases

Releases are prepared by [Release Please](https://github.com/googleapis/release-please). Use Conventional Commit subjects so the release PR can determine the next semantic version:

- `fix:` creates a patch release.
- `feat:` creates a minor release.
- `feat!:` or a `BREAKING CHANGE:` footer creates a major release.

Release Please maintains a reviewable release PR that updates `composer.json` and `CHANGELOG.md`. Merging that PR creates a `vX.Y.Z` tag and GitHub Release. The release workflow then attaches `LaioutrConnector-vX.Y.Z.zip` and its SHA-256 checksum and generates a GitHub artifact attestation for the ZIP.

Release Please pull requests created with the repository `GITHUB_TOKEN` do not automatically run pull-request workflows. Before merging one, run the **CI** workflow manually for its branch. Repository Actions settings must permit GitHub Actions to create and approve pull requests.

### Packagist

The canonical GitHub repository must be public before the package can be submitted to public Packagist. Submit `https://github.com/laioutr/shopware-laioutr-connector` once and authorize the Packagist GitHub integration. Packagist then indexes release tags automatically; no Packagist token or ZIP upload is needed in GitHub Actions.

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
