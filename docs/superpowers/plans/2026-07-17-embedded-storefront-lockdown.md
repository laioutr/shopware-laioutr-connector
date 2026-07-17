# Embedded Storefront Lockdown & Laioutr Bridge — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When embedded mode is on (default on, opt-out via config), the Shopware storefront redirects all non-flow routes to the cart, hides its header/footer/navigation, and loads a small script that talks to the Laioutr parent frame over `postMessage`.

**Architecture:** A `KernelEvents::REQUEST` subscriber consults a pure `RouteAllowlist` and 302s disallowed storefront routes to the cart. Twig template overrides (`base.html.twig`, `header-minimal`, `footer-minimal`) empty the chrome and inject a static JS bridge served from `Resources/public/` — no webpack, no CI build step. The bridge posts a namespaced, versioned `laioutr:*` message contract to a parent origin pinned via a handshake.

**Tech Stack:** PHP 8.2–8.5, Shopware 6.6–6.7 (Storefront), Symfony HttpKernel/Routing, Twig (`sw_extends`), vanilla ES5-safe browser JS, PHPUnit 10.5.

## Global Constraints

- PHP `~8.2 || ~8.3 || ~8.4 || ~8.5`; Shopware `~6.6 || ~6.7`; PSR-4 root `Laioutr\Connector\` → `src/`.
- **No storefront asset build.** No Node/webpack/SCSS; the plugin keeps its "no asset build required" property. The bridge is a hand-written file in `src/Resources/public/`.
- **No inline scripts** (CSP-clean). The bridge is an external same-origin `<script src>`; runtime data passes via `data-*` attributes.
- System-config keys are namespaced `LaioutrConnector.config.<field>`.
- **Lockdown is default-deny.** Redirect target is `frontend.checkout.cart.page`.
- **Embedded mode defaults ON** (config.xml `<defaultValue>true</defaultValue>`, applied at install). It is the opt-out.
- Bridge message envelope is exactly `{ source: 'laioutr-shopware', version: 1, type, payload }`.
- Bridge `targetOrigin` is the exact parent origin learned from the `laioutr:init` handshake, validated against `callbackDomainWildcard`; falls back to `'*'` only when no origins are configured.
- Conventional Commit subjects (Release Please): `feat:` / `fix:` / `docs:`.
- Follow existing conventions: constants like `DomainWhitelistValidator::CONFIG_KEY`; services registered in `src/Resources/config/services.yaml` under `_defaults: { autowire: true }`; unit tests mock `SystemConfigService`; integration tests use `IntegrationTestBehaviour` + `StorefrontControllerTestBehaviour`.

**Test prerequisite:** integration tests boot the Shopware kernel and force-install the plugin (`tests/TestBootstrap.php`). Run them inside the dev Shopware container. From the `shopware-dev/` directory:

```bash
docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter <TestClassOrMethod>
```

The full suite is `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit`.

---

## File structure

| File | Responsibility |
| --- | --- |
| `src/Embedded/Business/RouteAllowlist.php` (new) | Pure allow/deny decision for a route name under lockdown. |
| `src/Embedded/Subscriber/LockdownSubscriber.php` (new) | Redirect disallowed storefront routes to the cart when embedded mode is on. |
| `src/Resources/config/config.xml` (modify) | Add "Embedded storefront" card: `embeddedModeEnabled`, `lockdownAdditionalAllowedRoutes`. |
| `src/Resources/config/services.yaml` (modify) | Register `RouteAllowlist` + `LockdownSubscriber`. |
| `src/Resources/views/storefront/base.html.twig` (new) | Hide full-page chrome + inject the bridge, gated by config. |
| `src/Resources/views/storefront/layout/header/header-minimal.html.twig` (new) | Hide the checkout-page minimal header. |
| `src/Resources/views/storefront/layout/footer/footer-minimal.html.twig` (new) | Hide the checkout-page minimal footer. |
| `src/Resources/views/storefront/page/checkout/finish/index.html.twig` (new) | Expose the order id (CSP-clean `data-` attribute) for `laioutr:checkout-finish`. |
| `src/Resources/public/laioutr-embed.js` (new) | The `postMessage` bridge. |
| `tests/Unit/Embedded/Business/RouteAllowlistTest.php` (new) | Unit tests for the allowlist. |
| `tests/Integration/Embedded/LockdownSubscriberTest.php` (new) | Integration tests for the redirect. |
| `tests/Integration/Embedded/EmbeddedStorefrontRenderTest.php` (new) | Render test: chrome hidden + bridge injected. |
| `README.md` (modify) | Document embedded mode, config, and the bridge contract. |

---

## Task 1: `RouteAllowlist` — the allow/deny decision

**Files:**
- Create: `src/Embedded/Business/RouteAllowlist.php`
- Modify: `src/Resources/config/services.yaml`
- Test: `tests/Unit/Embedded/Business/RouteAllowlistTest.php`

**Interfaces:**
- Consumes: `Shopware\Core\System\SystemConfig\SystemConfigService` (autowired).
- Produces: `RouteAllowlist::isAllowed(string $route, ?string $salesChannelId = null): bool` and the constant `RouteAllowlist::CONFIG_KEY_ADDITIONAL_ROUTES = 'LaioutrConnector.config.lockdownAdditionalAllowedRoutes'`. Task 2 depends on both.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Embedded/Business/RouteAllowlistTest.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Embedded\Business;

use Laioutr\Connector\Embedded\Business\RouteAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class RouteAllowlistTest extends TestCase
{
    #[DataProvider('routeProvider')]
    public function testIsAllowed(string $additionalRoutes, string $route, bool $expected): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService
            ->method('getString')
            ->with(RouteAllowlist::CONFIG_KEY_ADDITIONAL_ROUTES, null)
            ->willReturn($additionalRoutes);

        $allowlist = new RouteAllowlist($systemConfigService);

        static::assertSame($expected, $allowlist->isAllowed($route));
    }

    public static function routeProvider(): iterable
    {
        yield 'cart allowed' => ['', 'frontend.checkout.cart.page', true];
        yield 'confirm allowed' => ['', 'frontend.checkout.confirm.page', true];
        yield 'finish allowed' => ['', 'frontend.checkout.finish.page', true];
        yield 'login allowed' => ['', 'frontend.account.login.page', true];
        yield 'register allowed' => ['', 'frontend.account.register.page', true];
        yield 'account order widget allowed' => ['', 'widgets.account.order.detail', true];
        yield 'checkout widget allowed' => ['', 'widgets.checkout.info', true];
        yield 'plugin session route allowed' => ['', 'frontend.laioutr.connect-session', true];
        yield 'plugin cookie bridge allowed' => ['', 'frontend.laioutr.cookie-bridge', true];
        yield 'error page allowed' => ['', 'error', true];
        yield 'csrf token allowed' => ['', 'frontend.csrf.generateToken', true];
        yield 'home denied' => ['', 'frontend.home.page', false];
        yield 'navigation denied' => ['', 'frontend.navigation.page', false];
        yield 'product detail denied' => ['', 'frontend.detail.page', false];
        yield 'search denied' => ['', 'frontend.search.page', false];
        yield 'cms widget denied' => ['', 'widgets.cms.page', false];
        yield 'wishlist denied' => ['', 'frontend.wishlist.page', false];
        yield 'additional route allowed' => ["frontend.example.return\n", 'frontend.example.return', true];
        yield 'additional route trims and ignores blanks' => ["\n  frontend.example.return  \n", 'frontend.example.return', true];
        yield 'unlisted route stays denied with blank config' => ["\n  \n", 'frontend.home.page', false];
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter RouteAllowlistTest`
Expected: FAIL — `Class "Laioutr\Connector\Embedded\Business\RouteAllowlist" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `src/Embedded/Business/RouteAllowlist.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Embedded\Business;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class RouteAllowlist
{
    public const CONFIG_KEY_ADDITIONAL_ROUTES = 'LaioutrConnector.config.lockdownAdditionalAllowedRoutes';

    /**
     * Route-name prefixes that stay reachable under lockdown: the cart,
     * checkout, account, and the plugin's own session routes, plus the widgets
     * those flows load over AJAX.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
        'frontend.checkout.',
        'frontend.account.',
        'frontend.cart.',
        'frontend.laioutr.',
        'widgets.account.',
        'widgets.checkout.',
    ];

    /**
     * Exact route names that stay reachable: error/maintenance pages and the
     * CSRF/cookie endpoints the allowed flows depend on.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'error',
        'frontend.csrf.generateToken',
        'frontend.cookie.offcanvas',
        'frontend.cookie.configuration',
        'frontend.maintenance.singlePage',
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isAllowed(string $route, ?string $salesChannelId = null): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        if (\in_array($route, self::ALLOWED_ROUTES, true)) {
            return true;
        }

        return \in_array($route, $this->getAdditionalAllowedRoutes($salesChannelId), true);
    }

    /**
     * @return list<string>
     */
    private function getAdditionalAllowedRoutes(?string $salesChannelId): array
    {
        $configured = preg_split(
            '/\r\n|\r|\n/',
            $this->systemConfigService->getString(self::CONFIG_KEY_ADDITIONAL_ROUTES, $salesChannelId),
        );

        if ($configured === false) {
            return [];
        }

        $routes = [];
        foreach ($configured as $route) {
            $route = trim($route);
            if ($route !== '') {
                $routes[] = $route;
            }
        }

        return $routes;
    }
}
```

- [ ] **Step 4: Register the service**

In `src/Resources/config/services.yaml`, add under the existing service definitions (after the `Session\Business` entries):

```yaml
    Laioutr\Connector\Embedded\Business\RouteAllowlist: ~
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter RouteAllowlistTest`
Expected: PASS (all data sets green).

- [ ] **Step 6: Commit**

```bash
git add src/Embedded/Business/RouteAllowlist.php src/Resources/config/services.yaml tests/Unit/Embedded/Business/RouteAllowlistTest.php
git commit -m "feat: add route allowlist for storefront lockdown"
```

---

## Task 2: `LockdownSubscriber` — redirect non-flow routes to the cart

**Files:**
- Create: `src/Embedded/Subscriber/LockdownSubscriber.php`
- Modify: `src/Resources/config/config.xml` (add the "Embedded storefront" card)
- Modify: `src/Resources/config/services.yaml` (register the subscriber)
- Test: `tests/Integration/Embedded/LockdownSubscriberTest.php`

**Interfaces:**
- Consumes: `RouteAllowlist::isAllowed()` (Task 1), `SystemConfigService`, `Symfony\Component\Routing\Generator\UrlGeneratorInterface`.
- Produces: `LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE = 'LaioutrConnector.config.embeddedModeEnabled'`. Task 3 tests depend on this constant.

- [ ] **Step 1: Add the config fields**

In `src/Resources/config/config.xml`, add a new `<card>` after the existing "Session callbacks" card (inside `<config>`):

```xml
    <card>
        <title>Embedded storefront</title>
        <title lang="de-DE">Eingebetteter Storefront</title>

        <input-field type="bool">
            <name>embeddedModeEnabled</name>
            <label>Enable embedded mode (lockdown, hidden header/footer, Laioutr bridge)</label>
            <label lang="de-DE">Eingebetteten Modus aktivieren (Lockdown, ausgeblendete Kopf-/Fußzeile, Laioutr-Bridge)</label>
            <helpText>When enabled, only the cart, checkout, and account flows stay reachable — every other storefront route redirects to the cart. Header and footer are hidden and the Laioutr iframe bridge is loaded. Enabled by default; disable to opt out.</helpText>
            <helpText lang="de-DE">Wenn aktiviert, bleiben nur Warenkorb-, Checkout- und Konto-Abläufe erreichbar — jede andere Storefront-Route wird zum Warenkorb umgeleitet. Kopf- und Fußzeile werden ausgeblendet und die Laioutr-Iframe-Bridge wird geladen. Standardmäßig aktiviert; zum Deaktivieren abwählen.</helpText>
            <defaultValue>true</defaultValue>
        </input-field>

        <input-field type="textarea">
            <name>lockdownAdditionalAllowedRoutes</name>
            <label>Additional allowed routes</label>
            <label lang="de-DE">Zusätzlich erlaubte Routen</label>
            <helpText>One storefront route name per line to keep reachable under lockdown, for example a payment plugin's return route (e.g. frontend.example.return).</helpText>
            <helpText lang="de-DE">Eine Storefront-Routenname pro Zeile, die unter Lockdown erreichbar bleiben soll, z. B. die Rückgabe-Route eines Payment-Plugins (etwa frontend.example.return).</helpText>
        </input-field>
    </card>
```

- [ ] **Step 2: Write the failing test**

Create `tests/Integration/Embedded/LockdownSubscriberTest.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration\Embedded;

use Laioutr\Connector\Embedded\Subscriber\LockdownSubscriber;
use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Test\Controller\StorefrontControllerTestBehaviour;
use Symfony\Component\HttpFoundation\Response;

class LockdownSubscriberTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StorefrontControllerTestBehaviour;

    private function setConfig(string $key, bool|string $value): void
    {
        static::getContainer()->get(SystemConfigService::class)->set($key, $value);
    }

    public function testDisallowedRouteRedirectsToCartWhenEmbedded(): void
    {
        $this->setConfig(LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE, true);

        $response = $this->request('GET', '', []);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
    }

    public function testAllowedRoutePassesThroughWhenEmbedded(): void
    {
        $this->setConfig(LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE, true);
        $this->setConfig(DomainWhitelistValidator::CONFIG_KEY, 'localhost');

        // The plugin's own cookie-bridge route is allowlisted (frontend.laioutr.*);
        // lockdown must let it reach the controller, which redirects to the callback.
        $response = $this->request('GET', 'laioutr/cookie-bridge', ['redirect-route' => 'http://localhost/callback']);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertSame('http://localhost/callback', $response->headers->get('Location'));
    }

    public function testDisallowedRouteIsNotRedirectedWhenDisabled(): void
    {
        $this->setConfig(LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE, false);

        $response = $this->request('GET', '', []);

        static::assertNotSame('/checkout/cart', (string) $response->headers->get('Location'));
    }

    public function testEmbeddedModeIsEnabledByDefault(): void
    {
        // No config set: the config.xml default (true) is applied at plugin install
        // (tests/TestBootstrap.php force-installs the plugin), so lockdown is active.
        $response = $this->request('GET', '', []);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter LockdownSubscriberTest`
Expected: FAIL — `Class "Laioutr\Connector\Embedded\Subscriber\LockdownSubscriber" not found`.

- [ ] **Step 4: Write the subscriber**

Create `src/Embedded/Subscriber/LockdownSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Embedded\Subscriber;

use Laioutr\Connector\Embedded\Business\RouteAllowlist;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class LockdownSubscriber implements EventSubscriberInterface
{
    public const CONFIG_KEY_EMBEDDED_MODE = 'LaioutrConnector.config.embeddedModeEnabled';

    private const REDIRECT_ROUTE = 'frontend.checkout.cart.page';

    public function __construct(
        private readonly RouteAllowlist $routeAllowlist,
        private readonly SystemConfigService $systemConfigService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 4 runs after Symfony's RouterListener (priority 32), so
        // `_route` and `_routeScope` are populated, and before the controller.
        return [
            KernelEvents::REQUEST => [['onRequest', 4]],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $scopes = $request->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, []);
        if (!\is_array($scopes) || !\in_array(StorefrontRouteScope::ID, $scopes, true)) {
            return;
        }

        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        $salesChannelId = \is_string($salesChannelId) ? $salesChannelId : null;

        if (!$this->systemConfigService->getBool(self::CONFIG_KEY_EMBEDDED_MODE, $salesChannelId)) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route) || $this->routeAllowlist->isAllowed($route, $salesChannelId)) {
            return;
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate(self::REDIRECT_ROUTE)),
        );
    }
}
```

- [ ] **Step 5: Register the subscriber**

In `src/Resources/config/services.yaml`, add:

```yaml
    Laioutr\Connector\Embedded\Subscriber\LockdownSubscriber:
        tags:
            - kernel.event_subscriber
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter LockdownSubscriberTest`
Expected: PASS (all four methods green). If `testDisallowedRouteRedirectsToCartWhenEmbedded` fails because `_route` is null, the subscriber priority is too high — confirm it is below 32.

- [ ] **Step 7: Commit**

```bash
git add src/Embedded/Subscriber/LockdownSubscriber.php src/Resources/config/config.xml src/Resources/config/services.yaml tests/Integration/Embedded/LockdownSubscriberTest.php
git commit -m "feat: redirect non-flow storefront routes to cart in embedded mode"
```

---

## Task 3: Chrome hiding + `laioutr:*` bridge

**Files:**
- Create: `src/Resources/views/storefront/base.html.twig`
- Create: `src/Resources/views/storefront/layout/header/header-minimal.html.twig`
- Create: `src/Resources/views/storefront/layout/footer/footer-minimal.html.twig`
- Create: `src/Resources/views/storefront/page/checkout/finish/index.html.twig`
- Create: `src/Resources/public/laioutr-embed.js`
- Test: `tests/Integration/Embedded/EmbeddedStorefrontRenderTest.php`

**Interfaces:**
- Consumes: `LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE` (Task 2) in the test; the Twig `config()` function and `asset()` at runtime.
- Produces: the rendered storefront (chrome hidden, `<script data-laioutr-embed>` present when embedded) and the `laioutr:*` postMessage contract for the `laioutr`-repo consumer (Plan B).

> **Note on JS testing:** the bridge is vanilla browser JS with no Node/test-runner (a build-free plugin, per the Global Constraints). Its *injection and data attributes* are asserted by the render test below; its *runtime behavior* is verified manually in the dev shop (Step 8). This matches the spec's testing strategy.

- [ ] **Step 1: Write the failing render test**

Create `tests/Integration/Embedded/EmbeddedStorefrontRenderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration\Embedded;

use Laioutr\Connector\Embedded\Subscriber\LockdownSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Test\Controller\StorefrontControllerTestBehaviour;

class EmbeddedStorefrontRenderTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StorefrontControllerTestBehaviour;

    private function setEmbedded(bool $enabled): void
    {
        static::getContainer()->get(SystemConfigService::class)->set(
            LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE,
            $enabled,
        );
    }

    public function testChromeHiddenAndBridgeInjectedWhenEmbedded(): void
    {
        $this->setEmbedded(true);

        // The cart page is allowlisted, so it renders instead of redirecting.
        $content = (string) $this->request('GET', 'checkout/cart', [])->getContent();

        static::assertStringContainsString('data-laioutr-embed', $content);
        static::assertStringNotContainsString('class="header-minimal"', $content);
    }

    public function testChromeShownAndNoBridgeWhenDisabled(): void
    {
        $this->setEmbedded(false);

        $content = (string) $this->request('GET', 'checkout/cart', [])->getContent();

        static::assertStringNotContainsString('data-laioutr-embed', $content);
        static::assertStringContainsString('class="header-minimal"', $content);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter EmbeddedStorefrontRenderTest`
Expected: FAIL — `testChromeHiddenAndBridgeInjectedWhenEmbedded` fails on the missing `data-laioutr-embed` string (no template override exists yet).

- [ ] **Step 3: Create the base template override (chrome + bridge injection)**

Create `src/Resources/views/storefront/base.html.twig`:

```twig
{% sw_extends '@Storefront/storefront/base.html.twig' %}

{# Laioutr embedded mode: hide storefront chrome and load the parent-frame bridge. #}
{% set laioutrEmbedded = config('LaioutrConnector.config.embeddedModeEnabled') %}

{# Full-page chrome. Both 6.6 (base_header/base_footer/base_navigation) and 6.7
   (base_esi_header/base_esi_footer) block names are overridden; a block that does
   not exist in a given version is a harmless no-op under sw_extends. #}
{% block base_header %}{% if not laioutrEmbedded %}{{ parent() }}{% endif %}{% endblock %}
{% block base_esi_header %}{% if not laioutrEmbedded %}{{ parent() }}{% endif %}{% endblock %}
{% block base_navigation %}{% if not laioutrEmbedded %}{{ parent() }}{% endif %}{% endblock %}
{% block base_offcanvas_navigation %}{% if not laioutrEmbedded %}{{ parent() }}{% endif %}{% endblock %}
{% block base_esi_footer %}{% if not laioutrEmbedded %}{{ parent() }}{% endif %}{% endblock %}
{% block base_footer %}{% if not laioutrEmbedded %}{{ parent() }}{% endif %}{% endblock %}
{% block base_scroll_up %}{% if not laioutrEmbedded %}{{ parent() }}{% endif %}{% endblock %}

{% block base_body_script %}
    {{ parent() }}
    {% if laioutrEmbedded %}
        <script defer
                src="{{ asset('bundles/laioutrconnector/laioutr-embed.js') }}"
                data-laioutr-embed
                data-route="{{ activeRoute }}"
                data-navigation-id="{{ shopware.navigation.id }}"
                data-sales-channel-id="{{ context.salesChannelId }}"
                data-allowed-origins="{{ config('LaioutrConnector.config.callbackDomainWildcard') }}"></script>
    {% endif %}
{% endblock %}
```

- [ ] **Step 4: Create the minimal header/footer overrides (checkout-page chrome)**

Checkout pages (cart/confirm/finish) render their own `base_esi_header` → `header-minimal`, which shadows the base override — so the minimal templates must be hidden directly.

Create `src/Resources/views/storefront/layout/header/header-minimal.html.twig`:

```twig
{% sw_extends '@Storefront/storefront/layout/header/header-minimal.html.twig' %}

{% block layout_header %}
    {% if not config('LaioutrConnector.config.embeddedModeEnabled') %}{{ parent() }}{% endif %}
{% endblock %}
```

Create `src/Resources/views/storefront/layout/footer/footer-minimal.html.twig`:

```twig
{% sw_extends '@Storefront/storefront/layout/footer/footer-minimal.html.twig' %}

{% block layout_footer_inner_container %}
    {% if not config('LaioutrConnector.config.embeddedModeEnabled') %}{{ parent() }}{% endif %}
{% endblock %}

{% block layout_footer_service_menu %}
    {% if not config('LaioutrConnector.config.embeddedModeEnabled') %}{{ parent() }}{% endif %}
{% endblock %}
```

- [ ] **Step 5: Create the finish-page order-id element (CSP-clean)**

Create `src/Resources/views/storefront/page/checkout/finish/index.html.twig`:

```twig
{% sw_extends '@Storefront/storefront/page/checkout/finish/index.html.twig' %}

{% block page_checkout_finish %}
    {{ parent() }}
    {% if config('LaioutrConnector.config.embeddedModeEnabled') %}
        <div data-laioutr-order-id="{{ page.order.id }}" hidden></div>
    {% endif %}
{% endblock %}
```

- [ ] **Step 6: Create the bridge module**

Create `src/Resources/public/laioutr-embed.js`:

```js
/**
 * Laioutr embedded-storefront bridge.
 *
 * Runs on every storefront page when embedded mode is on. Talks to the Laioutr
 * parent frame over postMessage: reports content height for iframe sizing and
 * notifies the parent of page loads, checkout completion, and password recovery.
 *
 * Every message uses the envelope { source, version, type, payload }. The parent
 * ignores anything without source === SOURCE. Data-bearing messages are buffered
 * until the parent completes the handshake (laioutr:init) so nothing with data is
 * broadcast to '*'; only the contentless laioutr:ready ping is.
 */
(function () {
  "use strict";

  var SOURCE = "laioutr-shopware";
  var VERSION = 1;

  var script = document.currentScript;
  var dataset = script ? script.dataset : {};
  var allowedOrigins = parseOrigins(dataset.allowedOrigins);

  var trustedOrigin = null; // exact parent origin, learned from the handshake
  var queue = []; // data-bearing messages buffered until the handshake completes

  function parseOrigins(raw) {
    if (!raw) {
      return [];
    }
    return raw
      .split(/\r\n|\r|\n/)
      .map(function (line) { return line.trim(); })
      .filter(function (line) { return line !== ""; });
  }

  // Match an origin against the configured host patterns (e.g. "*.example.com").
  function originAllowed(origin) {
    if (allowedOrigins.length === 0) {
      return true; // no allowlist configured: rely on the frame-ancestors CSP
    }
    var host;
    try {
      host = new URL(origin).host;
    } catch (e) {
      return false;
    }
    return allowedOrigins.some(function (pattern) {
      var re = new RegExp("^" + pattern.replace(/[.]/g, "\\.").replace(/\*/g, ".*") + "$");
      return re.test(host);
    });
  }

  function envelope(type, payload) {
    return { source: SOURCE, version: VERSION, type: type, payload: payload || {} };
  }

  function post(type, payload) {
    var message = envelope(type, payload);
    if (trustedOrigin) {
      window.parent.postMessage(message, trustedOrigin);
    } else {
      queue.push(message);
    }
  }

  function flushQueue() {
    if (!trustedOrigin) {
      return;
    }
    queue.forEach(function (message) {
      window.parent.postMessage(message, trustedOrigin);
    });
    queue = [];
  }

  function sendResize() {
    post("laioutr:resize", { height: document.body.scrollHeight });
  }

  function sendPageLoaded() {
    post("laioutr:page-loaded", {
      path: window.location.pathname,
      route: dataset.route || null,
      navigationId: dataset.navigationId || null,
      salesChannelId: dataset.salesChannelId || null
    });
  }

  function sendCheckoutFinish() {
    var el = document.querySelector("[data-laioutr-order-id]");
    if (el) {
      post("laioutr:checkout-finish", { orderId: el.getAttribute("data-laioutr-order-id") });
    }
  }

  function wirePasswordRecovery() {
    var btn = document.querySelector(".btn-pw-recovery, [data-laioutr-pw-recovery]");
    if (btn) {
      btn.addEventListener("click", function () {
        post("laioutr:pw-recovery", {});
      });
    }
  }

  // Inbound: complete the handshake when the trusted parent replies.
  window.addEventListener("message", function (event) {
    var data = event.data;
    if (!data || data.source !== SOURCE || data.type !== "laioutr:init") {
      return;
    }
    if (!originAllowed(event.origin)) {
      return;
    }
    trustedOrigin = event.origin;
    flushQueue();
  });

  function init() {
    // Contentless ping — safe to broadcast; invites the parent's laioutr:init.
    window.parent.postMessage(envelope("laioutr:ready", {}), "*");

    sendPageLoaded();
    sendResize();
    sendCheckoutFinish();
    wirePasswordRecovery();

    if (typeof ResizeObserver !== "undefined") {
      new ResizeObserver(sendResize).observe(document.body);
    }
    window.addEventListener("resize", sendResize);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
```

- [ ] **Step 7: Run the render test to verify it passes**

The `<script>` tag renders the asset URL regardless of physical install, so the assertions pass without `assets:install`. First clear the compiled template cache so the new overrides load:

```bash
docker compose exec web bin/console cache:clear
docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter EmbeddedStorefrontRenderTest
```

Expected: PASS (both methods green).

- [ ] **Step 8: Manually verify the bridge in the dev shop**

Install the asset and exercise the bridge end-to-end (there is no automated JS test):

```bash
docker compose exec web bin/console assets:install
```

Then, with embedded mode on, open a checkout/account page inside a minimal parent frame (a scratch HTML file that `postMessage`s `{source:'laioutr-shopware',version:1,type:'laioutr:init',payload:{}}` back on receiving `laioutr:ready`) and confirm in the console/network that: `laioutr:ready` is posted on load; after the parent's `laioutr:init` the buffered `laioutr:resize` / `laioutr:page-loaded` arrive at the pinned origin; resizing the frame re-posts `laioutr:resize`; completing an order posts `laioutr:checkout-finish` with the order id. Record the result.

- [ ] **Step 9: Commit**

```bash
git add src/Resources/views/storefront/base.html.twig \
        src/Resources/views/storefront/layout/header/header-minimal.html.twig \
        src/Resources/views/storefront/layout/footer/footer-minimal.html.twig \
        src/Resources/views/storefront/page/checkout/finish/index.html.twig \
        src/Resources/public/laioutr-embed.js \
        tests/Integration/Embedded/EmbeddedStorefrontRenderTest.php
git commit -m "feat: hide storefront chrome and inject Laioutr iframe bridge"
```

---

## Task 4: Documentation + full verification

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: everything above.
- Produces: accurate operator + integrator docs; a green full suite.

- [ ] **Step 1: Document embedded mode in the README**

In `README.md`, under the "Configuration" section (after the callback-domain paragraph), add:

````markdown
## Embedded storefront mode

When **Embedded mode** is enabled (default on — disable it in the plugin config to opt out), the storefront operates as the embedded commerce backend for a Laioutr-rendered frontend:

- **Lockdown** — every storefront route except the cart, checkout, account, and plugin session flows redirects to the cart. Add exceptions (e.g. a payment plugin's return route) under **Additional allowed routes**, one route name per line.
- **Hidden chrome** — the header, navigation, and footer are not rendered (Laioutr provides them).
- **Bridge** — `laioutr-embed.js` is loaded and talks to the Laioutr parent frame over `postMessage`.

Embedded mode is per-sales-channel (use the sales-channel selector in the plugin config). **Installing the plugin locks the storefront down immediately on every channel where the setting is on** — disable it on any channel that should keep the full storefront.

Run `bin/console assets:install` after installing/updating the plugin so `laioutr-embed.js` is published to `public/bundles/laioutrconnector/`.

### Bridge message contract

Every message is `{ source: 'laioutr-shopware', version: 1, type, payload }`. The bridge posts `laioutr:ready` to `*` on load, then buffers data-bearing messages until the parent replies with `laioutr:init`; its `event.origin` (validated against the allowed callback domains) becomes the pinned target for all later messages.

| Direction | `type` | `payload` |
| --- | --- | --- |
| shop → parent | `laioutr:ready` | `{}` |
| shop → parent | `laioutr:resize` | `{ height }` |
| shop → parent | `laioutr:page-loaded` | `{ path, route, navigationId, salesChannelId }` |
| shop → parent | `laioutr:checkout-finish` | `{ orderId }` |
| shop → parent | `laioutr:pw-recovery` | `{}` |
| parent → shop | `laioutr:init` | `{}` (its origin is pinned as the target) |
````

Also update the "Embedded storefront prerequisite" section to note that header/footer removal is now driven by embedded mode and still requires a `frame-ancestors` CSP at the deployment layer.

- [ ] **Step 2: Update the "no build" note**

The README's Development section states "No Administration or Storefront asset build is required because the plugin currently has no JavaScript, Twig, SCSS, or asset entrypoint." Replace it with:

```markdown
The plugin ships Twig template overrides and a single static JavaScript asset (`src/Resources/public/laioutr-embed.js`) served via `asset()`. No Administration or Storefront **build** is required — there is no webpack/Vite/SCSS entrypoint; run `bin/console assets:install` to publish the static asset.
```

- [ ] **Step 3: Run the full suite**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit`
Expected: PASS — all suites green (existing Session tests + the new Embedded tests).

- [ ] **Step 4: Run format and compatibility checks**

Run (from the plugin repo root, Docker required):

```bash
composer format
composer compatibility
```

Expected: formatting clean (or apply the reported fixes) and the extension validates against the lowest and highest supported Shopware versions (this also validates `config.xml`).

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs: document embedded storefront mode and bridge contract"
```

---

## Self-review notes (author)

- **Spec coverage:** §5 lockdown → Tasks 1–2; §6 chrome → Task 3 (base + minimal templates); §7 bridge + §7.1 contract + §7.2 origin pinning → Task 3 (JS) + Task 4 (docs); §8 config → Task 2; §9 structure/decomposition → file map + producer-only scope; §10 security (default-deny, no inline scripts, pinned origin) → Tasks 1–3; §11 testing → unit (Task 1) + integration redirect (Task 2) + render (Task 3) + manual JS (Task 3 Step 8); §4 default-on → Task 2 `testEmbeddedModeIsEnabledByDefault`.
- **Consumer (Plan B)** in the `laioutr` repo is out of scope here by design; the contract table (Task 4) is the authoritative interface it builds against.
- **Type consistency:** `RouteAllowlist::isAllowed(string, ?string): bool` and `CONFIG_KEY_ADDITIONAL_ROUTES` (Task 1) are consumed unchanged in Task 2; `LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE` (Task 2) is consumed unchanged in Task 3 tests; the envelope `{source,version,type,payload}` and the `data-laioutr-embed` / `data-laioutr-order-id` attribute names match between the Twig (Task 3 Steps 3/5) and the JS (Task 3 Step 6).
- **Open items deferred to execution (spec §12):** the exact baseline allowlist may need one or two additional `widgets.*`/CSRF routes once audited against the running 6.6/6.7 route tables — the escape-hatch config and the render/redirect tests will surface any gap.
```
