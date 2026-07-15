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

### `GET /laioutr/connect-session`

Starts the connection and redirects to a local Shopware route.

Required query parameters:

| Parameter | Meaning |
| --- | --- |
| `sw-context-token` | Context token stored in the Shopware session |
| `redirect-route` | Local Shopware route name, for example `frontend.account.login.page` |
| `login-success-callback` | Allowed external URL used after login or on an authenticated account page |
| `logout-success-callback` | Allowed external URL used after logout |

Example:

```text
/laioutr/connect-session?sw-context-token=…&redirect-route=frontend.account.login.page&login-success-callback=https%3A%2F%2Flaioutr.example.com%2Flogin&logout-success-callback=https%3A%2F%2Flaioutr.example.com%2Flogout
```

Callback redirects append the URL-encoded `from` route. The legacy connector also appended the Shopware context token. That callback payload field is intentionally left as a manual TODO pending security review.

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

A Shopware CLI-generated 6.6.10.20 project lives under `dev/` and mounts this repository into `custom/plugins/LaioutrConnector`.

```bash
cd dev
cp .env .env.local
# Set APP_SECRET and INSTANCE_ID in .env.local before first use.
docker compose up -d
docker compose exec web bin/console system:is-installed
docker compose exec web bin/console plugin:refresh
docker compose exec web bin/console plugin:install --activate LaioutrConnector
```

Run tests:

```bash
docker compose exec web ./vendor/bin/phpunit \
    --configuration custom/plugins/LaioutrConnector/phpunit.xml.dist
```

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
