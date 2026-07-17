# Design: Embedded storefront lockdown & Laioutr bridge

- **Date:** 2026-07-17
- **Status:** Draft (awaiting review)
- **Repos:** `shopware-laioutr-integration` (the plugin, producer) and `laioutr` (frontend consumer, coordinated)
- **Related:** `2026-07-17-connect-to-laioutr-design.md` (one-click connect), `2026-07-16-session-handoff-hardening-design.md` (the session handoff these flows sit on top of)

## 1. Goal

When the Laioutr Connector plugin is installed, the Shopware storefront should operate as the
**embedded commerce backend** for a Laioutr-rendered frontend. Laioutr owns the public pages
(home, category, product, content); the Shopware storefront — embedded via iframe — owns only the
cart → checkout → login/register → account flows.

Three behaviors realize this, all gated by one master toggle (default on, so it is an opt-out):

1. **Lockdown** — every storefront route except the checkout/account/cart/plugin flows redirects to
   the cart, so raw Shopware pages are not reachable or indexable.
2. **Chrome hidden** — the storefront header, navigation, and footer are not rendered, because that
   chrome comes from Laioutr.
3. **postMessage bridge** — a small script lets the embedded storefront talk to the Laioutr parent
   frame (report content height for iframe sizing, page-loaded, checkout-finish, password-recovery).

## 2. Prior art (depot shop)

The depot shop's theme plugin `GdcDepot` already implements the embedding behavior; this design
productizes it into the standalone connector and hardens it. What `GdcDepot` does today:

- **Chrome hiding** — unconditional Twig block overrides in `base.html.twig` empty `base_header`,
  `base_navigation`, `base_offcanvas_navigation`, `base_footer`, `base_scroll_up`. No config gate
  (that shop is always embedded).
- **Bridge** (`Resources/app/storefront/src/main.js`) posts to `window.parent` with
  `targetOrigin: '*'`: `swHeight {height}` (driven by a `MutationObserver` on `<body>` + `resize`),
  `swPageLoaded {path, route, navigationId, salesChannelId}` (reads `window.activeRoute` etc. that
  its `meta.html.twig` injects), and `swPwRecovery` (password-recovery click).
- **`swCheckoutFinish {order:{id}}`** — an **inline** `<script>` in the checkout finish template.
- **No lockdown / route restriction exists** — that is net-new here.

The Laioutr frontend that consumes these messages is **not present in the `laioutr` monorepo today**
(`packages/shopware` is currently server-side only). Re-namespacing the contract therefore breaks
nothing in-repo now; the consumer is a greenfield, coordinated deliverable (§9).

## 3. Decisions locked during brainstorming

| Decision | Choice | Rationale |
| --- | --- | --- |
| Chrome-hide trigger | **Always hide when embedded mode is on** (config toggle) | The checkout/account flows are only ever reached embedded in Laioutr. Sidesteps the HTTP-cache-variance bug that per-request iframe detection would introduce, and is dead simple and robust. |
| Injection surface | **No-build: Twig block overrides + a static JS module in `Resources/public/`** | Future-proof: Shopware is migrating the storefront build webpack→Vite in 6.8 (`@deprecated` notes in `base.html.twig`); a static asset served from `public/bundles/…` is immune to that churn. Preserves the plugin's "no asset build required" identity (no Node, no CI build step, no committed `dist/`, no CSP-nonce juggling). Still idiomatic — `sw_extends` block overrides and `asset()` are canonical, stable mechanisms. |
| Lockdown posture | **Default-deny allowlist**, redirect to `frontend.checkout.cart.page`, with a config escape hatch | Default-deny is the correct posture for a "lockdown"; the escape hatch (`lockdownAdditionalAllowedRoutes`) absorbs edge cases such as payment-provider async-return routes without loosening the default. |
| Redirect target | **Cart page** (`frontend.checkout.cart.page`) | Neutral, always-valid landing inside the allowed flow (an empty checkout-confirm bounces to the cart anyway). |
| Bridge contract | **Re-namespace to `laioutr:*` now** with a versioned envelope and a **pinned target origin** | Clean, namespaced, versionable contract established during productization; origin pinned (via handshake) for defense-in-depth. Requires a coordinated consumer in the `laioutr` repo, which is acceptable because no consumer exists in-repo yet. |
| Activation granularity | **Single master toggle** `embeddedModeEnabled` (default on) | "When the plugin is installed, the shop should be locked-down (with an option to opt-out)." One coherent feature; per-sales-channel system-config overrides give multi-channel merchants the needed scoping for free. |
| Trusted origins source | **Reuse `callbackDomainWildcard`** | One source of truth for "trusted Laioutr origins"; avoids a duplicate list. Splittable into a dedicated field later if the semantics diverge. |

### Rejected / deferred alternatives

- **Per-request iframe auto-detect** (`Sec-Fetch-Dest: iframe` + session marker) — rejected for chrome
  hiding: more precise for the rare top-level visit, but forces the storefront HTTP cache to `Vary`
  on the header or it serves embedded HTML to top-level visitors and vice-versa. Not worth it when
  the flows are only reached embedded.
- **Client-side CSS/JS-only chrome hiding** — rejected: causes a visible flash of the header before
  the script runs.
- **Full `Resources/app/storefront` webpack plugin** — rejected: canonical for JS behavior, but drags
  the webpack→Vite migration + a CI build step + committed artifacts in for ~40 lines of vanilla JS.
- **Preserve `sw*` names / `targetOrigin: '*'`** — rejected in favor of re-namespacing now, since there
  is no in-repo consumer to keep compatible and productization is the moment to set a clean contract.

## 4. Operating model

One master toggle `embeddedModeEnabled` (bool, **default true**). When true for a sales channel, all
three behaviors (§5, §6, §7) are active. Shopware system config is per-sales-channel out of the box,
so a merchant with multiple channels can enable embedded mode on the Laioutr channel only.

Consequences to document prominently in the README:

- **Default-on locks down a live shop immediately on install.** Coherent with the plugin's purpose
  (it *is* the Laioutr connector) and mitigated by per-channel scoping, but it must be called out.
- Embedded mode is **decoupled** from the "connected to Laioutr" connection state (§ the connect
  design). Gating lockdown on an established connection is a noted future refinement, not v1.

## 5. Lockdown redirect

- **`LockdownSubscriber`** on `KernelEvents::REQUEST`, at a priority that runs **after** Symfony's
  `RouterListener` (so `_route` and `_route_scope` are resolved) and before the controller.
- Guard: act only on **storefront-scoped** requests (`_route_scope` contains `storefront`). Store-API,
  admin/API, and static asset requests are never touched.
- Reads `embeddedModeEnabled` for the request's sales channel
  (`$request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID)`); returns early when off.
- Delegates the allow/deny decision to a pure **`RouteAllowlist`** service. If the route is not
  allowed, return a `RedirectResponse` to `frontend.checkout.cart.page`. The cart route is itself
  allowed, so there is no redirect loop.

### 5.1 `RouteAllowlist`

Pure, unit-testable: `isAllowed(string $route): bool`. Allowed by **route-name prefix**:

- `frontend.checkout.` — cart, confirm, finish, register, line-item, etc.
- `frontend.account.` — login, logout, register, recover, profile, order, address, payment,
  edit-order (covers payment plugins that hang off edit-order).
- `frontend.cart.` — offcanvas cart and line-item AJAX.
- `frontend.laioutr.` — the plugin's own session routes (`frontend.laioutr.connect-session`,
  `frontend.laioutr.cookie-bridge`).
- Error / maintenance / CSRF / cookie routes, plus the specific `widgets.*` used **inside** the
  allowed flows (e.g. `widgets.account.order.detail`).

Plus any route names from the **`lockdownAdditionalAllowedRoutes`** config (one per line; exact route
names). The exact baseline allowlist is enumerated during planning by auditing the storefront route
table for the supported Shopware versions; the design commits to **default-deny + escape hatch**, not
to a frozen list here.

## 6. Chrome hiding

- `src/Resources/views/storefront/base.html.twig` — `{% sw_extends '@Storefront/storefront/base.html.twig' %}`.
- When `config('LaioutrConnector.config.embeddedModeEnabled')` is truthy for the current sales
  channel, override the chrome blocks to render nothing. To be robust across 6.6→6.7 block renames,
  override the **full set**: `base_header`, `base_esi_header`, `base_navigation`,
  `base_offcanvas_navigation`, `base_footer`, `base_esi_footer`, `base_scroll_up`. Overriding a block
  that does not exist in a given version is a harmless no-op under `sw_extends`.
- Server-side and always-on in embedded mode, so there is no flash and no HTTP-cache variance.
- Pairs with the already-documented deployment requirement to restrict embedding with a
  `frame-ancestors` CSP (the plugin already strips `X-Frame-Options`).

## 7. postMessage bridge

- `src/Resources/public/laioutr-embed.js` — a plain, self-contained ES module. Served at
  `bundles/laioutrconnector/laioutr-embed.js` after `assets:install`.
- Injected in the `base_body_script` block (same `base.html.twig` override) **when embedded mode is
  on**:
  `<script defer src="{{ asset('bundles/laioutrconnector/laioutr-embed.js') }}" data-laioutr-embed
  data-route="{{ activeRoute }}" data-navigation-id="{{ shopware.navigation.id }}" data-sales-channel-id="{{ context.salesChannelId }}"
  data-allowed-origins="{{ config('LaioutrConnector.config.callbackDomainWildcard') }}"></script>`.
  The bridge reads its own tag via `document.currentScript.dataset` — no `window` globals, no inline
  script (external same-origin script is allowed by `script-src 'self'`, so no CSP nonce needed).
- Checkout finish: `src/Resources/views/storefront/page/checkout/finish/index.html.twig` adds the
  order id via a CSP-clean `data-` attribute (e.g. on the bridge script or a dedicated element),
  **replacing GdcDepot's inline `<script>`**. The bridge, on `frontend.checkout.finish.page`, reads
  it and emits `laioutr:checkout-finish`.

### 7.1 Contract (`laioutr:*`)

Every message uses the envelope: `{ source: 'laioutr-shopware', version: 1, type, payload }`. The
`source` discriminator lets the parent ignore unrelated postMessage traffic (framework devtools, etc.).

**Shop → parent:**

| `type` | `payload` | Trigger |
| --- | --- | --- |
| `laioutr:ready` | `{}` | Bridge loaded — contentless handshake ping. |
| `laioutr:resize` | `{ height }` | `ResizeObserver` on `<body>` + `load` / `resize`. |
| `laioutr:page-loaded` | `{ path, route, navigationId, salesChannelId }` | On load. |
| `laioutr:checkout-finish` | `{ orderId }` | On `frontend.checkout.finish.page`. |
| `laioutr:pw-recovery` | `{}` | Password-recovery click. |

**Parent → shop:**

| `type` | Meaning |
| --- | --- |
| `laioutr:init` | Handshake reply. Its `event.origin`, validated against the trusted-origins config, becomes the **pinned `targetOrigin`** for all subsequent shop→parent messages. |

### 7.2 Origin pinning

- On load the bridge sends `laioutr:ready` to `'*'` (contentless — safe to broadcast).
- The parent replies with `laioutr:init`. The bridge validates `event.origin` against
  `callbackDomainWildcard` (host wildcards adapted to origins); on match it stores the exact origin
  and uses it as `targetOrigin` for everything thereafter.
- **Outbound messages that carry data (resize, page-loaded, checkout-finish) are buffered until the
  handshake completes**, then flushed to the pinned origin — nothing with data is ever posted to
  `'*'`.
- If no trusted origins are configured, the bridge falls back to `'*'` (documented as the less-secure
  mode); the security boundary in that case is the deployment's `frame-ancestors` CSP.

### 7.3 Improvements over `GdcDepot`

Namespaced + versioned envelope; `source` discriminator; pinned origin with buffered handshake;
`ResizeObserver` instead of `MutationObserver`; CSP-clean checkout-finish (no inline script).

## 8. Config surface (`src/Resources/config/config.xml`)

New card **"Embedded storefront"**:

- `embeddedModeEnabled` — bool, `defaultValue` true. Master opt-out. Help text notes it locks the
  storefront down to the checkout/account flows, hides header/footer, and enables the Laioutr bridge.
- `lockdownAdditionalAllowedRoutes` — textarea. One extra storefront route name per line to keep
  reachable under lockdown (e.g. a payment plugin's async-return route).

Existing card **"Session callbacks"** (`callbackDomainWildcard`) is unchanged and additionally serves
as the trusted-origin list for the bridge (§7.2).

## 9. Structure, wiring, and decomposition

New `src/Embedded/` domain, mirroring the existing `src/Session/` layout:

```
src/Embedded/Subscriber/LockdownSubscriber.php      (KernelEvents::REQUEST)
src/Embedded/Business/RouteAllowlist.php            (pure allow/deny decision)
src/Resources/views/storefront/base.html.twig       (chrome hide + bridge inject)
src/Resources/views/storefront/page/checkout/finish/index.html.twig  (order id for checkout-finish)
src/Resources/public/laioutr-embed.js               (the bridge)
src/Resources/config/config.xml                     (+ Embedded storefront card)
src/Resources/config/services.yaml                  (+ LockdownSubscriber, RouteAllowlist)
```

`LockdownSubscriber` is tagged `kernel.event_subscriber`; `RouteAllowlist` is a plain autowired
service reading `SystemConfigService`. No new migration, no new route, no admin build.

**Decomposition (producer / consumer):**

- **Plan A — this repo (producer):** lockdown subscriber + allowlist, chrome-hide Twig, bridge asset
  + finish-page order id, config additions, tests, README update.
- **Plan B — `laioutr` repo (consumer):** the `laioutr:*` listener + handshake in the `packages/shopware`
  embedding component. Separate spec/plan; this document defines the contract (§7) authoritatively so
  B can build against it.

## 10. Security model

- **Default-deny lockdown** — unknown/new routes are redirected, not leaked; the escape hatch is
  explicit and additive.
- **Chrome removal + `frame-ancestors`** — header/footer removal is paired with the existing
  documented requirement to restrict embedding via a `frame-ancestors` CSP (plugin already strips
  `X-Frame-Options`).
- **Pinned postMessage origin** — data-bearing messages target a single origin validated against the
  trusted-origins allowlist; buffered until handshake so nothing with data hits `'*'`.
- **No inline scripts** — bridge is an external same-origin asset; context passes via `data-`
  attributes. CSP-clean, including the checkout-finish path that was previously inline.

## 11. Testing strategy

- **`RouteAllowlist` (unit):** allowed prefixes pass; content routes (home, navigation, detail,
  search, CMS, wishlist, newsletter) denied; escape-hatch routes from config pass; empty config
  denies content routes.
- **`LockdownSubscriber` (integration):** disallowed storefront route → 302 to cart; allowed route →
  pass-through; non-storefront scope → untouched; `embeddedModeEnabled=false` → untouched; no redirect
  loop on the cart route.
- **Chrome hiding (dev / manual):** an automated storefront-render test proved infeasible — Shopware's
  PHPUnit harness installs without a theme (`TestBootstrapper --no-assign-theme`), so plugin storefront
  Twig overrides do not resolve under it (confirmed via `debug:twig`). Verified instead against a
  running dev shop with a theme assigned: header/footer/navigation absent when embedded mode is on,
  present when off, with the bridge `<script>` injected.
- **Bridge (manual / dev):** thin vanilla JS — verified in the dev shop embedded in a test parent
  frame (handshake, pinned origin, resize, page-loaded, checkout-finish, pw-recovery).

## 12. Open items to resolve in planning (not design blockers)

- **Exact baseline allowlist** — enumerate the storefront route table for 6.6 and 6.7; confirm the
  `widgets.*` and CSRF/cookie/error/maintenance routes needed inside the allowed flows.
- **Finish-page order-id delivery** — data attribute on the bridge script vs a dedicated element;
  pick in Plan A.
- **Reading per-sales-channel config in Twig and in the subscriber** — confirm `config()` resolves the
  active channel in the storefront and that `ATTRIBUTE_SALES_CHANNEL_ID` is populated at the chosen
  subscriber priority.
- **`callbackDomainWildcard` → origin matching** — adapt host wildcards to `event.origin`
  (scheme+host) matching for §7.2.
- **Consumer coordination** — sequence Plan B so the `laioutr:*` listener ships together with, or
  ahead of, enabling the re-namespaced contract in production.
```

