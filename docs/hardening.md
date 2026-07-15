# Follow-up hardening

The initial extraction preserves the integration flow where possible and makes packaging, dependency injection, validation, redirect construction, and response handling testable. The following changes require a coordinated protocol or deployment decision and are intentionally deferred.

## Session and callback protocol

- Replace the context-token query contract with signed, expiring, single-use state bound to the browser session and intended callback.
- Define replay protection and CSRF/state validation for connect-session and cookie-bridge.
- Resolve the manual callback-payload TODO without exposing the Shopware context token in callback URLs, browser history, referrers, reverse-proxy logs, or analytics.
- Decide whether login/logout callback values should be consumed once and removed from the session.
- Define behavior for callback URLs that already contain `from` or other connector-owned query keys.
- Return stable protocol-level error responses instead of framework exception pages.
- Add rate limits and privacy-safe audit events; redact all tokens and callback state from logs.

## Callback policy

- Require HTTPS outside explicit local-development environments.
- Define allowed schemes, ports, userinfo, IP literals, IDN normalization, and DNS-rebinding behavior.
- Revisit wildcard semantics and whether the apex domain should be explicit.
- Consider per-sales-channel allowlists rather than one global configuration value.

## Embedding and browser security

Global `X-Frame-Options` removal is currently required for the embedded integration to function. Replace it with an explicit, narrowly maintained embedding policy:

- configure CSP `frame-ancestors` with the exact Laioutr origins;
- decide whether embedding can be scoped to a sales channel or integration session;
- verify headers at the final reverse proxy/CDN as well as in Shopware;
- test SameSite=None, Secure cookies, third-party-cookie blocking, storage partitioning, and browser-specific iframe behavior.

## Compatibility and operations

- Add a versioned protocol contract shared with the Laioutr consumer.
- Add end-to-end browser tests for login, authenticated account navigation, logout, and blocked third-party-cookie behavior.
- Add supported Shopware 6.6/PHP CI matrices and a separate Shopware 6.7 migration track before widening Composer constraints.
