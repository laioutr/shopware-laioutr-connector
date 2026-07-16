# Follow-up hardening

The initial extraction preserves the integration flow where possible and makes packaging, dependency injection, validation, redirect construction, and response handling testable. The following changes require a coordinated protocol or deployment decision and are intentionally deferred.

## Session and callback protocol

- **Implemented.** The context-token query contract is replaced with a signed, expiring (60s), single-use code exchange: `POST /store-api/laioutr/session-handoff` mints the code server-to-server, and `GET /laioutr/connect-session?code=…` redeems it once, bound to the issuing sales channel. See `docs/superpowers/specs/2026-07-16-session-handoff-hardening-design.md`.
- **Implemented.** The context token is never placed in a browser URL; only the opaque single-use code travels via redirect. See `docs/superpowers/specs/2026-07-16-session-handoff-hardening-design.md`.
- Define replay protection and CSRF/state validation for connect-session and cookie-bridge.
- **Resolved.** The callback payload exposes only the URL-encoded `from` route, never the Shopware context token.
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
- Maintain CI coverage for both supported Shopware 6.6 and 6.7 release lines.
