# Session handoff hardening — one-time code exchange (Approach A)

- **Status:** Draft for review
- **Date:** 2026-07-16
- **Scope:** Hardening problem #1 from `docs/hardening.md` — the unauthenticated context-token handoff on `/laioutr/connect-session`. Clickjacking (#2) and redirect-allowlist hardening (#3) are separate specs.

## 1. Problem & current behavior

`ConnectController::connectSession` accepts a raw `sw-context-token` as a query parameter and writes it straight into the visitor's storefront session, with no proof it came from laioutr, no freshness, and no single-use:

```
GET /laioutr/connect-session
    ?sw-context-token=<raw token>
    &redirect-route=<internal route>
    &login-success-callback=<url>
    &logout-success-callback=<url>
```

`sw-context-token` is a **bearer credential** for a cart/sales-channel context. Because the endpoint accepts any value from anyone, an attacker can fixate a chosen context into a victim's browser (see threat model). The raw token also travels in the URL, so it lands in browser history, `Referer` headers, and access logs.

## 2. Goals / Non-goals

**Goals**
- The raw `sw-context-token` never travels through the browser (URL, history, `Referer`, logs, client JS).
- A handoff artifact is authentic (provably issued by laioutr), fresh (short TTL), and single-use (exactly-once redemption).
- Works on **every** Shopware installation, single-node or clustered, with no dependency beyond what Shopware itself requires.
- Reduce cross-user cart fixation to the residual minimum with session hygiene.
- Preserve the existing login/logout callback behaviour; finalize its payload contract so no token is exposed.

**Non-goals (separate specs)**
- Clickjacking / `X-Frame-Options` (`docs/hardening.md` #2).
- Redirect-allowlist / wildcard hardening (`docs/hardening.md` #3), beyond re-validating callbacks at redeem time.
- Per-sales-channel allowlists, HTTPS enforcement policy.

## 3. Threat model

The token handoff faces two distinct attack layers.

- **Layer 1 — arbitrary injection / replay (must close).** Attacker crafts the connect URL with a guessed, phished, or stale token and links a victim to it. Closed by authenticity + short TTL + single-use.
- **Layer 2 — cross-user cart fixation (reduce to residual).** Attacker obtains a *legitimate* handoff for **their own** cart and phishes a victim into redeeming it. The victim then shops/checks-out inside the attacker's context; since the attacker also holds that context token, PII the victim enters can be read back via the Store API. Signing/coding does **not** close this — the artifact is genuine. Mitigated (not eliminated) by short TTL, single-use, storefront-session regeneration, and Shopware's context-token rotation at login.

**Product decision — the storefront session is subordinate to laioutr.** laioutr is authoritative over which user is logged in, so a redeemed handoff **overwrites** any existing storefront session — guest *or* authenticated — rather than refusing when a different customer is present. This intentionally drops the "don't override an authenticated session" defense.

**Accepted residual risk.** Overwriting means Layer-2 fixation is *not* blocked even when the storefront session is already authenticated; it is bounded only by single-use + short TTL + session-id regeneration + login-boundary token rotation, and by the requirement that the handoff be freshly minted for laioutr's current session. This is acceptable **on the assumption that users do not independently authenticate to the storefront outside the laioutr-driven flow** — the storefront is an embedded surface that always mirrors laioutr's context. If direct storefront login (outside laioutr) becomes a supported path, revisit this decision.

Non-goals for the threat model: theft of the token from laioutr's own storage, or a fully compromised app database — both are outside this endpoint's control.

## 4. Chosen approach & rationale

**Approach A — one-time code exchange (OAuth authorization-code style).** laioutr's backend (which already holds the token in an http-only cookie and has Store API access) exchanges the token for an opaque, single-use `code` at click time; only the `code` travels through the browser; the plugin redeems it server-side and installs the context.

Why A over an HMAC-signed token (Approach B):
- **Token confidentiality.** HMAC signs but does not encrypt — B's blob *contains* the token and transits the browser. A keeps the raw token entirely server-side.
- **No standing secret.** A reuses laioutr's existing Store API access key; B introduces a shared signing secret whose leak enables unlimited forgery.
- **No added cost in practice.** Both need server-side single-use state anyway, so B's "stateless" advantage does not materialize.

**Why a database table, not a cache, for the code store.** The store must survive ~60s and be visible at redemption across all app nodes. A best-effort cache pool may evict early or be per-node; **Redis is not guaranteed** on a Shopware install. The one persistent, shared, durable store guaranteed on every Shopware deployment is the SQL database (via DBAL). It also gives atomic single-use for free. The failure mode is fail-closed regardless: a missing code aborts the handoff (user retries) and never mutates a session or corrupts the cart.

## 5. Architecture

New and changed units (following the existing `Session/{Business,Integration,Storefront,Subscriber}` layout):

| Unit | Type | Responsibility |
|---|---|---|
| `Session/StoreApi/Controller/SessionHandoffController` | new | Store-API route `POST /store-api/laioutr/session-handoff`. Mints a code for the authenticated context. |
| `Session/Business/SessionHandoffCodeService` | new | Generate 256-bit codes, hash them, encapsulate TTL policy. |
| `Session/Integration/SessionHandoffStore` | new | DBAL gateway: insert, atomic redeem, GC of `laioutr_session_handoff`. |
| `Migration/Migration1752XXXXXXCreateLaioutrSessionHandoff` | new | Create the table (auto-run on install/update). |
| `LaioutrConnector` | changed | Add `uninstall()` to drop `laioutr_session_handoff` unless `UninstallContext::keepUserData()`. |
| `Session/Storefront/Controller/ConnectController` | changed | Accept `code` instead of raw token; redeem; session hygiene; redirect. |
| `Session/Integration/SessionStorage` | unchanged | Still stores context token + callbacks in the session. |
| `Session/Business/DomainWhitelistValidator` | reused | Validate callbacks at mint time and re-validate at redeem. |

Boundaries: the controller does HTTP only; code/hash policy lives in the service; all SQL lives in the store; the whitelist validator stays the single source of callback-domain truth.

## 6. Protocol flow

```mermaid
sequenceDiagram
    autonumber
    actor U as Browser (user)
    participant L as Laioutr backend<br/>(laioutr domain)
    participant S as Shopware plugin<br/>(shop domain)
    participant DB as laioutr_session_handoff<br/>(SQL, short-TTL, single-use)

    Note over U,L: User on a laioutr page clicks "Checkout".<br/>http-only cookie (sw-context-token) is sent automatically.
    U->>L: GET /handoff  (cookie: sw-context-token)
    L->>L: Read context token from http-only cookie

    rect rgb(235,245,255)
    Note over L,S: Server-to-server, authenticated (sw-access-key + sw-context-token headers).<br/>Raw token never touches the browser.
    L->>S: POST /store-api/laioutr/session-handoff { loginSuccessCallback, logoutSuccessCallback, redirectRoute }
    S->>S: Resolve SalesChannelContext (validates token); validate callbacks vs allowlist
    S->>S: code = base64url(random_bytes(32))
    S->>DB: INSERT token_hash=sha256(code), context_token, sales_channel_id, callbacks, redirect_route, expires_at=now+60s
    S-->>L: 200 { code }
    end

    L-->>U: 302 -> /laioutr/connect-session?code=…   (opaque code only)
    U->>S: GET /laioutr/connect-session?code=…

    rect rgb(235,255,235)
    Note over S,DB: Redeem (atomic, exactly-once)
    S->>DB: BEGIN; SELECT … FOR UPDATE WHERE token_hash=sha256(code) AND expires_at>now; DELETE; COMMIT
    DB-->>S: payload  (or none -> fail closed)
    S->>S: refuse if a *different* customer is already logged in
    S->>S: re-validate callbacks; install context token + callbacks into session
    S->>S: regenerate session id (migrate(true))
    end

    S-->>U: 302 -> redirect_route (checkout)
    U->>S: GET /checkout  (session now maps to the cart)
    S-->>U: Checkout page with the correct basket
```

## 7. Data model — `laioutr_session_handoff`

| Column | Type | Notes |
|---|---|---|
| `id` | `BINARY(16)` | PK (UUIDv4). |
| `token_hash` | `VARBINARY(32)` | `UNIQUE`. SHA-256 of the code — the raw code is never stored, so a DB read cannot yield a usable code. |
| `context_token` | `VARCHAR(255)` | The `sw-context-token` to install. Stored in cleartext for the code's ~60s lifetime; the same class of secret Shopware already persists for carts/sessions. |
| `sales_channel_id` | `BINARY(16)` | Sales channel the context belongs to; checked at redeem. |
| `login_success_callback` | `VARCHAR(2048)` NULL | Validated against the allowlist at mint. |
| `logout_success_callback` | `VARCHAR(2048)` NULL | Validated against the allowlist at mint. |
| `redirect_route` | `VARCHAR(255)` NULL | Internal Shopware route to land on after redeem. |
| `expires_at` | `DATETIME(3)` | Indexed. Redemption requires `expires_at > now()`. |
| `created_at` | `DATETIME(3)` | Audit. |

**GC:** opportunistic only — each insert first runs `DELETE … WHERE expires_at < now()`. Volume is one row per checkout click, so no scheduled task is needed.

**Atomic single-use:** MySQL/MariaDB lack `DELETE … RETURNING`, so redeem runs in one transaction: `SELECT … FOR UPDATE` (row lock) → read payload → `DELETE` → `COMMIT`. A concurrent second redemption blocks on the lock, then finds no row and fails closed. Equivalent alternative: an atomic claim `UPDATE … SET redeemed_at=now() WHERE token_hash=:h AND redeemed_at IS NULL AND expires_at>now()` and require `rowCount() === 1`.

**Schema lifecycle (automatic).** The table is created by a standard Shopware plugin migration in `src/Migration/` (a `MigrationStep` subclass). Shopware's plugin lifecycle runs plugin migrations **automatically on install and on update** — no console command or manual step. Table creation is non-destructive, so it lives in the migration's `update()` method (always auto-run); there is no `updateDestructive()` change. On **uninstall**, the plugin drops the table only when `UninstallContext::keepUserData()` is `false`; the table holds only ephemeral handoff rows, so dropping is safe. This makes `LaioutrConnector::uninstall()` a small addition to the currently-empty plugin class.

## 8. Endpoint contracts

### 8.1 Mint — `POST /store-api/laioutr/session-handoff`
- **Scope:** `store-api`. **Auth:** sales-channel access key (`sw-access-key` header), required by the scope. The context to hand off is the request's resolved `sw-context-token`. Minting therefore requires *knowing* the token — it does not widen who can create handoffs beyond token holders.
- **Body:** `{ loginSuccessCallback, logoutSuccessCallback, redirectRoute }`.
- **Behavior:** resolve `SalesChannelContext` (framework validates the token); validate both callbacks against `DomainWhitelistValidator` (reject with a stable error if invalid); generate `code`; insert the row; return `{ code }`.
- **Response:** `200 { "code": "…" }`; `400` stable error on invalid callback; `401/403` on missing/invalid access key (framework).

### 8.2 Redeem — `GET /laioutr/connect-session?code=…`
- **URL now carries only `code`.** Token, callbacks, and redirect route are no longer in the URL — they live in the minted row.
- **Behavior:**
  1. Atomically redeem `code` (§7). Missing/expired → stable fail-closed error, no session mutation.
  2. Verify `sales_channel_id` matches the current sales channel (defense in depth).
  3. Re-validate callbacks against the allowlist; **overwrite** the session context — `SessionStorage->setContextToken()`, `setLoginSuccessCallback()`, `setLogoutSuccessCallback()` — replacing any existing context (guest **or** authenticated), since laioutr is authoritative over identity (§3). Shopware re-resolves the `SalesChannelContext` from the new token.
  4. `session->migrate(true)` to regenerate the session id.
  5. `302` to `redirect_route`.

## 9. Security controls

- **Confidentiality:** raw token only ever moves laioutr→Shopware over the authenticated TLS call and is stored hashed-by-reference (`token_hash`) — never in the browser, never in cleartext in the URL/logs.
- **Authenticity:** codes are 256-bit random and only mintable via the access-key-authenticated endpoint by a caller that already holds the token.
- **Freshness:** `expires_at` (default 60s, configurable) enforced at redeem.
- **Single-use:** atomic transactional redeem (§7).
- **Session hygiene:** overwrite the session context unconditionally (laioutr is authoritative over identity — §3), then regenerate the session id on bind (`migrate(true)`).
- **Login-boundary rotation (dependency to verify):** the residual Layer-2 fixation is contained by Shopware rotating the storefront context token at customer login, so an attacker's pre-login token stops mapping to the victim's authenticated context. **Verification that Shopware 6.6/6.7 storefront login rotates the context token is a required implementation check;** if it does not, add explicit rotation.

## 10. Error handling & rate limiting

- All failure paths return a **stable protocol-level response** (small error view / documented error code), never a framework exception page or stack trace. Invalid input on the current controller throws `InvalidArgumentException` (500) — replace with fail-closed handling.
- **Rate-limit** `/laioutr/connect-session` (code-guessing is already infeasible at 256 bits, but this bounds abuse) and the mint endpoint. Concrete limits are a follow-up.

## 11. Callback payload contract (resolves the `CallbackRedirector` TODO)

The login/logout callback URL receives **only** a `from=<internal route name>` query parameter and never the context token or any session secret. `CallbackRedirector::buildRedirectUrl` already appends only `from`; this spec finalizes that as the contract and removes the `// TODO: Finalize the callback payload contract` marker. Behaviour for callback URLs that already contain a `from` key is defined as: the connector's `from` is appended as an additional pair (existing keys are left intact) — revisit if laioutr needs override semantics.

## 12. Rollout & backward compatibility

This is a **breaking protocol change**: laioutr must adopt the mint step and send `code` instead of `sw-context-token`. There is **no dual-mode fallback** that still accepts a raw token — keeping that path open would leave the vulnerability in place. Ship the plugin release and the laioutr change as a **coordinated cutover**, versioned in the shared protocol contract (`docs/hardening.md` "Compatibility and operations").

## 13. Testing plan

- **Unit:** code generation length/charset; SHA-256 hashing; TTL policy; callback validation at mint; `SessionHandoffStore` redeem returns payload once and refuses the second call; expired code refused.
- **Integration:** mint requires a valid access key; mint returns a code and persists a row; connect-session redeems once and installs the context; second redeem of the same code fails closed; expired code fails closed; an existing *authenticated* session is **overwritten** with the handed-off context (not refused); session id changes after redeem; **assert the context token never appears in any response URL, `Location` header, or the connect-session query string.**
- **E2E (follow-up, per `docs/hardening.md`):** browser login → authenticated navigation → logout → checkout with third-party cookies blocked.

## 14. Resolved decisions

1. **TTL** — **60s** (fixed).
2. **Already-logged-in behavior** — **overwrite** the session unconditionally; laioutr is authoritative over identity (see §3 product decision + accepted residual risk).
3. **Callback binding** — callbacks + redirect route are bound into the minted row at **mint time**; the redeem URL carries only the opaque `code`.
4. **GC** — **opportunistic-on-insert only**; no scheduled task.

Remaining implementation-time verification (not a design choice): confirm Shopware 6.6/6.7 storefront login rotates the context token (§9); add explicit rotation if it does not.

## 15. Related follow-ups (out of scope here)

- `X-Frame-Options` / `frame-ancestors` clickjacking hardening (#2).
- Redirect-allowlist / wildcard-semantics hardening (#3).
- HTTPS-only enforcement, per-sales-channel allowlists, privacy-safe audit events.
