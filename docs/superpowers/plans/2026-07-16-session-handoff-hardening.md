# Session Handoff Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the unauthenticated raw `sw-context-token` handoff on `/laioutr/connect-session` with a one-time, short-lived, single-use `code` that laioutr mints server-to-server, so the raw context token never travels through the browser.

**Architecture:** laioutr's backend calls a new authenticated Store-API route to exchange the context token for an opaque `code`, persisted single-use in a new `laioutr_session_handoff` table. The browser carries only the `code` to `/laioutr/connect-session`, which redeems it atomically (delete-on-read), overwrites the storefront session context, regenerates the session id, and redirects to the stored route.

**Tech Stack:** PHP 8.2+, Shopware 6.6/6.7 (Store API + Storefront), Doctrine DBAL, Symfony HttpFoundation/Routing, PHPUnit 10.5.

## Global Constraints

- **PHP:** `~8.2 || ~8.3 || ~8.4 || ~8.5`. Use constructor promotion, `readonly`, strict types (`declare(strict_types=1);` in every PHP file).
- **Shopware:** `~6.6 || ~6.7`. No new Composer dependencies — only `shopware/core` + `shopware/storefront` (already required).
- **Namespaces:** production `Laioutr\Connector\` → `src/`; tests `Laioutr\Connector\Tests\` → `tests/`.
- **Tests:** PHPUnit 10.5 with attributes (`#[DataProvider]`). Run **from the `shopware-dev/` directory**:
  ```bash
  docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter '<FilterExpression>'
  ```
  Run the whole suite by dropping the `-- --filter '<...>'`. The plugin is force-installed for the test kernel (`tests/TestBootstrap.php`), so migrations run and the table exists in the test DB. `getContainer()` in Shopware test behaviours exposes **private** services, so new services can stay private (`~`) and still be fetched in tests.
- **Commits:** Conventional Commits (`feat:`, `test:`, `refactor:`, `docs:`), matching the repo's release-please setup. Commit at the end of each task as instructed.
- **Fail closed:** every invalid/missing/expired/used input must abort with an HTTP error and must not mutate the session.

## File Structure

**Create:**
- `src/Session/Business/SessionHandoffCodeService.php` — pure code generation, SHA-256 hashing, TTL policy.
- `src/Session/Integration/SessionHandoff.php` — readonly DTO returned by the store.
- `src/Session/Integration/SessionHandoffStore.php` — DBAL gateway: `issue()`, atomic `redeem()`, opportunistic GC.
- `src/Session/StoreApi/Controller/SessionHandoffController.php` — Store-API mint route.
- `src/Migration/Migration1784160000CreateLaioutrSessionHandoff.php` — creates the table.
- `tests/Unit/Session/Business/SessionHandoffCodeServiceTest.php`
- `tests/Integration/Migration/HandoffTableMigrationTest.php`
- `tests/Integration/Session/Integration/SessionHandoffStoreTest.php`
- `tests/Unit/Session/StoreApi/Controller/SessionHandoffControllerTest.php`

**Modify:**
- `src/LaioutrConnector.php` — add `uninstall()` that drops the table unless `keepUserData()`.
- `src/Session/Integration/SessionStorage.php` — add `regenerate()`.
- `src/Session/Storefront/Controller/ConnectController.php` — accept `code`, redeem, overwrite session, regenerate, redirect.
- `src/Session/Integration/CallbackRedirector.php` — remove the resolved security TODO comment.
- `src/Resources/config/routes.yaml` — register the Store-API controller directory.
- `src/Resources/config/services.yaml` — wire the new services.
- `tests/Integration/ConnectorRouteTest.php` — rewrite the connect-session test for `code`; add rejection tests.
- `tests/Unit/Session/Integration/SessionStorageTest.php` — add `regenerate()` test.
- `tests/Integration/PluginWiringTest.php` — assert the Store-API route.
- `README.md` — document the new mint + code-based connect protocol.

---

### Task 1: `SessionHandoffCodeService` (code generation, hashing, TTL)

**Files:**
- Create: `src/Session/Business/SessionHandoffCodeService.php`
- Test: `tests/Unit/Session/Business/SessionHandoffCodeServiceTest.php`

**Interfaces:**
- Produces:
  - `SessionHandoffCodeService::TTL_SECONDS` (int `60`)
  - `generateCode(): string` — 64-char lowercase hex (32 random bytes)
  - `hashCode(string $code): string` — raw-binary SHA-256 (32 bytes)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Session/Business/SessionHandoffCodeServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Business;

use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use PHPUnit\Framework\TestCase;

class SessionHandoffCodeServiceTest extends TestCase
{
    public function testGeneratesDistinctHighEntropyHexCodes(): void
    {
        $service = new SessionHandoffCodeService();

        $first = $service->generateCode();
        $second = $service->generateCode();

        static::assertNotSame($first, $second);
        static::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }

    public function testHashCodeIsDeterministicRawSha256(): void
    {
        $service = new SessionHandoffCodeService();

        static::assertSame(hash('sha256', 'a-code', true), $service->hashCode('a-code'));
        static::assertSame(32, \strlen($service->hashCode('a-code')));
    }

    public function testTtlIsSixtySeconds(): void
    {
        static::assertSame(60, SessionHandoffCodeService::TTL_SECONDS);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run (from `shopware-dev/`): `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'SessionHandoffCodeServiceTest'`
Expected: FAIL — `Class "Laioutr\Connector\Session\Business\SessionHandoffCodeService" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Session/Business/SessionHandoffCodeService.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Business;

class SessionHandoffCodeService
{
    public const TTL_SECONDS = 60;

    public function generateCode(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashCode(string $code): string
    {
        return hash('sha256', $code, true);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'SessionHandoffCodeServiceTest'`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Session/Business/SessionHandoffCodeService.php tests/Unit/Session/Business/SessionHandoffCodeServiceTest.php
git commit -m "feat: add session handoff code service"
```

---

### Task 2: Migration + uninstall cleanup for `laioutr_session_handoff`

**Files:**
- Create: `src/Migration/Migration1784160000CreateLaioutrSessionHandoff.php`
- Modify: `src/LaioutrConnector.php` (replace the empty class body)
- Test: `tests/Integration/Migration/HandoffTableMigrationTest.php`

**Interfaces:**
- Produces: table `laioutr_session_handoff` with columns `id` BINARY(16) PK, `token_hash` VARBINARY(32) UNIQUE, `context_token` VARCHAR(255), `sales_channel_id` BINARY(16), `login_success_callback`/`logout_success_callback` VARCHAR(2048) NULL, `redirect_route` VARCHAR(255) NULL, `expires_at` DATETIME(3) indexed, `created_at` DATETIME(3).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Migration/HandoffTableMigrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

class HandoffTableMigrationTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testHandoffTableExists(): void
    {
        $connection = static::getContainer()->get(Connection::class);

        static::assertSame(
            'laioutr_session_handoff',
            $connection->fetchOne("SHOW TABLES LIKE 'laioutr_session_handoff'"),
        );
    }

    public function testHandoffTableHasUniqueTokenHash(): void
    {
        $connection = static::getContainer()->get(Connection::class);

        $indexes = $connection->fetchAllAssociative('SHOW INDEX FROM `laioutr_session_handoff`');
        $uniqueOnTokenHash = array_filter(
            $indexes,
            static fn (array $row): bool => $row['Column_name'] === 'token_hash' && (int) $row['Non_unique'] === 0,
        );

        static::assertNotEmpty($uniqueOnTokenHash);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'HandoffTableMigrationTest'`
Expected: FAIL — table does not exist (the migration file is not present yet).

- [ ] **Step 3: Write the migration**

Create `src/Migration/Migration1784160000CreateLaioutrSessionHandoff.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1784160000CreateLaioutrSessionHandoff extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784160000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `laioutr_session_handoff` (
                `id` BINARY(16) NOT NULL,
                `token_hash` VARBINARY(32) NOT NULL,
                `context_token` VARCHAR(255) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `login_success_callback` VARCHAR(2048) NULL,
                `logout_success_callback` VARCHAR(2048) NULL,
                `redirect_route` VARCHAR(255) NULL,
                `expires_at` DATETIME(3) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.laioutr_session_handoff.token_hash` (`token_hash`),
                KEY `idx.laioutr_session_handoff.expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
```

- [ ] **Step 4: Add uninstall cleanup**

Replace the entire contents of `src/LaioutrConnector.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class LaioutrConnector extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->container->get(Connection::class)
            ->executeStatement('DROP TABLE IF EXISTS `laioutr_session_handoff`');
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

The test kernel force-installs the plugin (running the migration). Run:
`docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'HandoffTableMigrationTest'`
Expected: PASS (2 tests).

> If the table is not picked up because the test DB was built before this migration existed, rebuild it once: `docker compose exec web bin/console database:migrate --all LaioutrConnector` then re-run. In a clean CI run the force-install handles it automatically.

- [ ] **Step 6: Commit**

```bash
git add src/Migration/Migration1784160000CreateLaioutrSessionHandoff.php src/LaioutrConnector.php tests/Integration/Migration/HandoffTableMigrationTest.php
git commit -m "feat: add laioutr_session_handoff table with uninstall cleanup"
```

---

### Task 3: `SessionHandoff` DTO + `SessionHandoffStore` (issue / atomic redeem / GC)

**Files:**
- Create: `src/Session/Integration/SessionHandoff.php`
- Create: `src/Session/Integration/SessionHandoffStore.php`
- Test: `tests/Integration/Session/Integration/SessionHandoffStoreTest.php`

**Interfaces:**
- Consumes: `SessionHandoffCodeService::hashCode()`, `SessionHandoffCodeService::TTL_SECONDS` (Task 1); table from Task 2.
- Produces:
  - `SessionHandoff` readonly DTO: `string $contextToken`, `string $salesChannelId` (lowercase hex), `?string $loginSuccessCallback`, `?string $logoutSuccessCallback`, `?string $redirectRoute`.
  - `SessionHandoffStore::issue(string $code, string $contextToken, string $salesChannelId, ?string $loginSuccessCallback, ?string $logoutSuccessCallback, ?string $redirectRoute): void` — `$salesChannelId` is a hex string.
  - `SessionHandoffStore::redeem(string $code): ?SessionHandoff` — atomic delete-on-read; `null` when missing/expired/already used.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Session/Integration/SessionHandoffStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration\Session\Integration;

use Doctrine\DBAL\Connection;
use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\TestDefaults;

class SessionHandoffStoreTest extends TestCase
{
    use IntegrationTestBehaviour;

    private SessionHandoffCodeService $codeService;

    private SessionHandoffStore $store;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->codeService = static::getContainer()->get(SessionHandoffCodeService::class);
        $this->store = static::getContainer()->get(SessionHandoffStore::class);
        $this->connection = static::getContainer()->get(Connection::class);
    }

    public function testIssuedCodeIsRedeemableExactlyOnce(): void
    {
        $code = $this->codeService->generateCode();
        $this->store->issue(
            $code,
            'ctx-token',
            TestDefaults::SALES_CHANNEL,
            'https://allowed.example/login',
            'https://allowed.example/logout',
            'frontend.account.home.page',
        );

        $handoff = $this->store->redeem($code);

        static::assertNotNull($handoff);
        static::assertSame('ctx-token', $handoff->contextToken);
        static::assertSame(TestDefaults::SALES_CHANNEL, $handoff->salesChannelId);
        static::assertSame('https://allowed.example/login', $handoff->loginSuccessCallback);
        static::assertSame('https://allowed.example/logout', $handoff->logoutSuccessCallback);
        static::assertSame('frontend.account.home.page', $handoff->redirectRoute);

        static::assertNull($this->store->redeem($code), 'code must be single-use');
    }

    public function testUnknownCodeReturnsNull(): void
    {
        static::assertNull($this->store->redeem($this->codeService->generateCode()));
    }

    public function testExpiredCodeReturnsNull(): void
    {
        $code = $this->codeService->generateCode();
        $this->connection->insert('laioutr_session_handoff', [
            'id' => Uuid::randomBytes(),
            'token_hash' => $this->codeService->hashCode($code),
            'context_token' => 'ctx-token',
            'sales_channel_id' => Uuid::fromHexToBytes(TestDefaults::SALES_CHANNEL),
            'login_success_callback' => null,
            'logout_success_callback' => null,
            'redirect_route' => 'frontend.account.home.page',
            'expires_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s.v'),
            'created_at' => (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s.v'),
        ]);

        static::assertNull($this->store->redeem($code));
    }

    public function testIssueRemovesExpiredRows(): void
    {
        $staleCode = $this->codeService->generateCode();
        $this->connection->insert('laioutr_session_handoff', [
            'id' => Uuid::randomBytes(),
            'token_hash' => $this->codeService->hashCode($staleCode),
            'context_token' => 'stale',
            'sales_channel_id' => Uuid::fromHexToBytes(TestDefaults::SALES_CHANNEL),
            'login_success_callback' => null,
            'logout_success_callback' => null,
            'redirect_route' => null,
            'expires_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s.v'),
            'created_at' => (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s.v'),
        ]);

        $this->store->issue($this->codeService->generateCode(), 'ctx', TestDefaults::SALES_CHANNEL, null, null, null);

        $remaining = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM laioutr_session_handoff WHERE token_hash = :hash',
            ['hash' => $this->codeService->hashCode($staleCode)],
        );
        static::assertSame(0, (int) $remaining);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'SessionHandoffStoreTest'`
Expected: FAIL — `SessionHandoffStore` / `SessionHandoff` not found.

- [ ] **Step 3: Write the DTO**

Create `src/Session/Integration/SessionHandoff.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Integration;

class SessionHandoff
{
    public function __construct(
        public readonly string $contextToken,
        public readonly string $salesChannelId,
        public readonly ?string $loginSuccessCallback,
        public readonly ?string $logoutSuccessCallback,
        public readonly ?string $redirectRoute,
    ) {
    }
}
```

- [ ] **Step 4: Write the store**

Create `src/Session/Integration/SessionHandoffStore.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Integration;

use Doctrine\DBAL\Connection;
use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Shopware\Core\Framework\Uuid\Uuid;

class SessionHandoffStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SessionHandoffCodeService $codeService,
    ) {
    }

    public function issue(
        string $code,
        string $contextToken,
        string $salesChannelId,
        ?string $loginSuccessCallback,
        ?string $logoutSuccessCallback,
        ?string $redirectRoute,
    ): void {
        $now = new \DateTimeImmutable();

        // Opportunistic GC: one row per checkout click keeps this cheap.
        $this->connection->executeStatement(
            'DELETE FROM laioutr_session_handoff WHERE expires_at < :now',
            ['now' => $now->format('Y-m-d H:i:s.v')],
        );

        $this->connection->insert('laioutr_session_handoff', [
            'id' => Uuid::randomBytes(),
            'token_hash' => $this->codeService->hashCode($code),
            'context_token' => $contextToken,
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'login_success_callback' => $loginSuccessCallback,
            'logout_success_callback' => $logoutSuccessCallback,
            'redirect_route' => $redirectRoute,
            'expires_at' => $now->modify(
                sprintf('+%d seconds', SessionHandoffCodeService::TTL_SECONDS),
            )->format('Y-m-d H:i:s.v'),
            'created_at' => $now->format('Y-m-d H:i:s.v'),
        ]);
    }

    public function redeem(string $code): ?SessionHandoff
    {
        $hash = $this->codeService->hashCode($code);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        return $this->connection->transactional(
            function (Connection $connection) use ($hash, $now): ?SessionHandoff {
                $row = $connection->fetchAssociative(
                    'SELECT context_token,
                            LOWER(HEX(sales_channel_id)) AS sales_channel_id,
                            login_success_callback,
                            logout_success_callback,
                            redirect_route
                       FROM laioutr_session_handoff
                      WHERE token_hash = :hash AND expires_at > :now
                      FOR UPDATE',
                    ['hash' => $hash, 'now' => $now],
                );

                if ($row === false) {
                    return null;
                }

                $connection->executeStatement(
                    'DELETE FROM laioutr_session_handoff WHERE token_hash = :hash',
                    ['hash' => $hash],
                );

                return new SessionHandoff(
                    (string) $row['context_token'],
                    (string) $row['sales_channel_id'],
                    $row['login_success_callback'] !== null ? (string) $row['login_success_callback'] : null,
                    $row['logout_success_callback'] !== null ? (string) $row['logout_success_callback'] : null,
                    $row['redirect_route'] !== null ? (string) $row['redirect_route'] : null,
                );
            },
        );
    }
}
```

- [ ] **Step 5: Wire the services**

In `src/Resources/config/services.yaml`, add these lines below the existing `Laioutr\Connector\Session\Business\DomainWhitelistValidator: ~` entry:

```yaml
    Laioutr\Connector\Session\Business\SessionHandoffCodeService: ~
    Laioutr\Connector\Session\Integration\SessionHandoffStore: ~
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'SessionHandoffStoreTest'`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Session/Integration/SessionHandoff.php src/Session/Integration/SessionHandoffStore.php src/Resources/config/services.yaml tests/Integration/Session/Integration/SessionHandoffStoreTest.php
git commit -m "feat: add single-use session handoff store"
```

---

### Task 4: `SessionStorage::regenerate()`

**Files:**
- Modify: `src/Session/Integration/SessionStorage.php`
- Test: `tests/Unit/Session/Integration/SessionStorageTest.php`

**Interfaces:**
- Produces: `SessionStorage::regenerate(): void` — regenerates the session id, preserving data (`migrate(true)`).

- [ ] **Step 1: Write the failing test**

Append this method to `tests/Unit/Session/Integration/SessionStorageTest.php` (inside the class):

```php
    public function testRegenerateChangesSessionIdKeepingData(): void
    {
        $this->session->start();
        $this->session->set('keep', 'value');
        $before = $this->session->getId();

        $this->sessionStorage->regenerate();

        static::assertNotSame('', $this->session->getId());
        static::assertNotSame($before, $this->session->getId());
        static::assertSame('value', $this->session->get('keep'));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'SessionStorageTest::testRegenerateChangesSessionIdKeepingData'`
Expected: FAIL — `Call to undefined method ...::regenerate()`.

- [ ] **Step 3: Write the implementation**

In `src/Session/Integration/SessionStorage.php`, add this method directly above the private `get()` method:

```php
    public function regenerate(): void
    {
        $this->getSession()->migrate(true);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'SessionStorageTest'`
Expected: PASS (all `SessionStorageTest` tests).

- [ ] **Step 5: Commit**

```bash
git add src/Session/Integration/SessionStorage.php tests/Unit/Session/Integration/SessionStorageTest.php
git commit -m "feat: regenerate session id on session storage"
```

---

### Task 5: `SessionHandoffController` mint route + wiring

**Files:**
- Create: `src/Session/StoreApi/Controller/SessionHandoffController.php`
- Modify: `src/Resources/config/routes.yaml`
- Modify: `src/Resources/config/services.yaml`
- Modify: `tests/Integration/PluginWiringTest.php`
- Test: `tests/Unit/Session/StoreApi/Controller/SessionHandoffControllerTest.php`

**Interfaces:**
- Consumes: `DomainWhitelistValidator::isValidUrl()`, `SessionHandoffCodeService::generateCode()`, `SessionHandoffStore::issue()`.
- Produces: `POST /store-api/laioutr/session-handoff` (route name `store-api.laioutr.session-handoff`) returning `{"code": "<hex>"}`. Reads POST body params `login-success-callback`, `logout-success-callback`, `redirect-route`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Session/StoreApi/Controller/SessionHandoffControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\StoreApi\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Laioutr\Connector\Session\StoreApi\Controller\SessionHandoffController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SessionHandoffControllerTest extends TestCase
{
    public function testIssuesCodeForAllowedCallbacks(): void
    {
        $validator = $this->createMock(DomainWhitelistValidator::class);
        $validator->method('isValidUrl')->willReturn(true);

        $codeService = $this->createMock(SessionHandoffCodeService::class);
        $codeService->method('generateCode')->willReturn('generated-code');

        $store = $this->createMock(SessionHandoffStore::class);
        $store->expects(static::once())->method('issue')->with(
            'generated-code',
            'ctx-token',
            'sales-channel-id',
            'https://allowed.example/login',
            'https://allowed.example/logout',
            'frontend.checkout.cart.page',
        );

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getToken')->willReturn('ctx-token');
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        $controller = new SessionHandoffController($validator, $codeService, $store);

        $response = $controller->issue($this->requestWith([
            'login-success-callback' => 'https://allowed.example/login',
            'logout-success-callback' => 'https://allowed.example/logout',
            'redirect-route' => 'frontend.checkout.cart.page',
        ]), $context);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(['code' => 'generated-code'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsCallbackOutsideAllowlist(): void
    {
        $validator = $this->createMock(DomainWhitelistValidator::class);
        $validator->method('isValidUrl')->willReturn(false);

        $store = $this->createMock(SessionHandoffStore::class);
        $store->expects(static::never())->method('issue');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getToken')->willReturn('ctx-token');
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        $controller = new SessionHandoffController(
            $validator,
            $this->createMock(SessionHandoffCodeService::class),
            $store,
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->issue($this->requestWith([
            'login-success-callback' => 'https://evil.example/login',
            'logout-success-callback' => 'https://allowed.example/logout',
            'redirect-route' => 'frontend.checkout.cart.page',
        ]), $context);
    }

    public function testRejectsMissingRedirectRoute(): void
    {
        $validator = $this->createMock(DomainWhitelistValidator::class);
        $validator->method('isValidUrl')->willReturn(true);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getToken')->willReturn('ctx-token');
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        $controller = new SessionHandoffController(
            $validator,
            $this->createMock(SessionHandoffCodeService::class),
            $this->createMock(SessionHandoffStore::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->issue($this->requestWith([
            'login-success-callback' => 'https://allowed.example/login',
            'logout-success-callback' => 'https://allowed.example/logout',
        ]), $context);
    }

    /**
     * @param array<string, string> $body
     */
    private function requestWith(array $body): Request
    {
        return new Request([], $body);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'SessionHandoffControllerTest'`
Expected: FAIL — controller class not found.

- [ ] **Step 3: Write the controller**

Create `src/Session/StoreApi/Controller/SessionHandoffController.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\StoreApi\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class SessionHandoffController
{
    public function __construct(
        private readonly DomainWhitelistValidator $domainWhitelistValidator,
        private readonly SessionHandoffCodeService $codeService,
        private readonly SessionHandoffStore $store,
    ) {
    }

    #[Route(
        path: '/store-api/laioutr/session-handoff',
        name: 'store-api.laioutr.session-handoff',
        methods: ['POST'],
    )]
    public function issue(Request $request, SalesChannelContext $context): JsonResponse
    {
        $loginSuccessCallback = $this->getRequiredBodyParameter($request, 'login-success-callback');
        $logoutSuccessCallback = $this->getRequiredBodyParameter($request, 'logout-success-callback');
        $redirectRoute = $this->getRequiredBodyParameter($request, 'redirect-route');

        if (
            !$this->domainWhitelistValidator->isValidUrl($loginSuccessCallback)
            || !$this->domainWhitelistValidator->isValidUrl($logoutSuccessCallback)
        ) {
            throw new BadRequestHttpException('Callback domain is not allowed');
        }

        $code = $this->codeService->generateCode();

        $this->store->issue(
            $code,
            $context->getToken(),
            $context->getSalesChannelId(),
            $loginSuccessCallback,
            $logoutSuccessCallback,
            $redirectRoute,
        );

        return new JsonResponse(['code' => $code]);
    }

    private function getRequiredBodyParameter(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        if (!\is_string($value) || trim($value) === '') {
            throw new BadRequestHttpException(sprintf('Parameter "%s" must be a non-empty string', $name));
        }

        return $value;
    }
}
```

- [ ] **Step 4: Register the Store-API controller directory**

Append to `src/Resources/config/routes.yaml`:

```yaml

laioutr_connector_store_api:
    resource: ../../Session/StoreApi/Controller/*Controller.php
    type: attribute
```

- [ ] **Step 5: Wire the controller service**

In `src/Resources/config/services.yaml`, add below the existing `CookieRedirectController` entry:

```yaml
    Laioutr\Connector\Session\StoreApi\Controller\SessionHandoffController:
        public: true
```

- [ ] **Step 6: Assert the route is registered**

In `tests/Integration/PluginWiringTest.php`, add this assertion at the end of `testServicesAndRoutesAreAvailable()`:

```php
        static::assertSame(
            '/store-api/laioutr/session-handoff',
            $routeCollection->get('store-api.laioutr.session-handoff')?->getPath(),
        );
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'SessionHandoffControllerTest|PluginWiringTest'`
Expected: PASS (SessionHandoffControllerTest: 3 tests; PluginWiringTest: 1 test).

- [ ] **Step 8: Commit**

```bash
git add src/Session/StoreApi/Controller/SessionHandoffController.php src/Resources/config/routes.yaml src/Resources/config/services.yaml tests/Unit/Session/StoreApi/Controller/SessionHandoffControllerTest.php tests/Integration/PluginWiringTest.php
git commit -m "feat: add store-api session handoff mint route"
```

---

### Task 6: Redeem the code in `ConnectController` (overwrite session, regenerate, redirect)

**Files:**
- Modify: `src/Session/Storefront/Controller/ConnectController.php`
- Modify: `src/Session/Integration/CallbackRedirector.php` (remove resolved TODO)
- Modify: `tests/Integration/ConnectorRouteTest.php`

**Interfaces:**
- Consumes: `SessionHandoffStore::redeem()` (Task 3), `SessionStorage::regenerate()` (Task 4), `DomainWhitelistValidator::isValidUrl()`, `SalesChannelContext::getSalesChannelId()`.
- Produces: `GET /laioutr/connect-session?code=<hex>` — redeems, overwrites session context, regenerates session id, `302` to the stored route. `400` on any invalid/expired/used code, foreign sales channel, or disallowed callback.

- [ ] **Step 1: Rewrite the connect-session integration tests**

Replace `testConnectSessionRedirectsToShopwareRoute()` in `tests/Integration/ConnectorRouteTest.php` with the following, and add the two new tests. Also add the imports `use Laioutr\Connector\Session\Business\SessionHandoffCodeService;`, `use Laioutr\Connector\Session\Integration\SessionHandoffStore;`, `use Shopware\Core\Framework\Uuid\Uuid;`, and `use Shopware\Core\Test\TestDefaults;` at the top of the file:

```php
    public function testConnectSessionRedirectsToShopwareRoute(): void
    {
        $code = static::getContainer()->get(SessionHandoffCodeService::class)->generateCode();
        static::getContainer()->get(SessionHandoffStore::class)->issue(
            $code,
            'test-context-token',
            TestDefaults::SALES_CHANNEL,
            'http://localhost/login-callback',
            'http://localhost/logout-callback',
            'frontend.account.login.page',
        );

        $response = $this->request('GET', 'laioutr/connect-session', ['code' => $code]);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertSame('/account/login', $response->headers->get('Location'));
        // Spec §13: the context token must never leak into the redirect target.
        static::assertStringNotContainsString(
            'test-context-token',
            (string) $response->headers->get('Location'),
        );
    }

    public function testConnectSessionRejectsUnknownCode(): void
    {
        $response = $this->request('GET', 'laioutr/connect-session', ['code' => 'does-not-exist']);

        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testConnectSessionRejectsForeignSalesChannel(): void
    {
        $code = static::getContainer()->get(SessionHandoffCodeService::class)->generateCode();
        static::getContainer()->get(SessionHandoffStore::class)->issue(
            $code,
            'test-context-token',
            Uuid::randomHex(),
            'http://localhost/login-callback',
            'http://localhost/logout-callback',
            'frontend.account.login.page',
        );

        $response = $this->request('GET', 'laioutr/connect-session', ['code' => $code]);

        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'ConnectorRouteTest'`
Expected: FAIL — the current controller still expects `sw-context-token` and does not read `code` (the new tests error/mismatch).

- [ ] **Step 3: Rewrite `ConnectController`**

Replace the entire contents of `src/Session/Storefront/Controller/ConnectController.php`:

```php
<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Storefront\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Laioutr\Connector\Session\Integration\SessionStorage;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ConnectController
{
    public function __construct(
        private readonly DomainWhitelistValidator $domainWhitelistValidator,
        private readonly SessionStorage $sessionStorage,
        private readonly SessionHandoffStore $sessionHandoffStore,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(
        path: '/laioutr/connect-session',
        name: 'frontend.laioutr.connect-session',
        methods: ['GET'],
    )]
    public function connectSession(Request $request, SalesChannelContext $context): Response
    {
        $code = $this->getRequiredQueryParameter($request, 'code');

        $handoff = $this->sessionHandoffStore->redeem($code);
        if ($handoff === null) {
            throw new BadRequestHttpException('Invalid or expired handoff code');
        }

        if (!hash_equals($handoff->salesChannelId, $context->getSalesChannelId())) {
            throw new BadRequestHttpException('Handoff was issued for a different sales channel');
        }

        foreach ([$handoff->loginSuccessCallback, $handoff->logoutSuccessCallback] as $callback) {
            if ($callback !== null && !$this->domainWhitelistValidator->isValidUrl($callback)) {
                throw new BadRequestHttpException('Callback domain is not allowed');
            }
        }

        if ($handoff->redirectRoute === null) {
            throw new BadRequestHttpException('Handoff is missing a redirect route');
        }

        $this->sessionStorage->setContextToken($handoff->contextToken);
        if ($handoff->loginSuccessCallback !== null) {
            $this->sessionStorage->setLoginSuccessCallback($handoff->loginSuccessCallback);
        }
        if ($handoff->logoutSuccessCallback !== null) {
            $this->sessionStorage->setLogoutSuccessCallback($handoff->logoutSuccessCallback);
        }
        $this->sessionStorage->regenerate();

        return new RedirectResponse(
            $this->urlGenerator->generate($handoff->redirectRoute),
            Response::HTTP_FOUND,
        );
    }

    private function getRequiredQueryParameter(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            throw new BadRequestHttpException(sprintf('Query parameter "%s" must be a non-empty string', $name));
        }

        return $value;
    }
}
```

- [ ] **Step 4: Remove the resolved TODO in `CallbackRedirector`**

In `src/Session/Integration/CallbackRedirector.php`, delete the line:

```php
        // TODO: Finalize the callback payload contract after security review.
```

(The payload contract is finalized by this work: callbacks receive only `from=<route>`, never the context token — already covered by `CallbackRedirectorTest::testBuildsEncodedCallbackUrlWithoutDisclosingContextToken`.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit -- --filter 'ConnectorRouteTest'`
Expected: PASS. `testConnectSessionRedirectsToShopwareRoute`, `testConnectSessionRejectsUnknownCode`, and `testConnectSessionRejectsForeignSalesChannel` all green; the untouched cookie-bridge tests still pass.

> If `testConnectSessionRedirectsToShopwareRoute` returns `400` instead of `302`, the storefront test request resolved a sales channel other than `TestDefaults::SALES_CHANNEL`. Fetch the resolved id by dumping `$context->getSalesChannelId()` in a throwaway assertion and use that id in `issue()`.

- [ ] **Step 6: Commit**

```bash
git add src/Session/Storefront/Controller/ConnectController.php src/Session/Integration/CallbackRedirector.php tests/Integration/ConnectorRouteTest.php
git commit -m "feat: redeem one-time code instead of raw context token on connect-session"
```

---

### Task 7: Documentation — new handoff protocol

**Files:**
- Modify: `README.md`
- Modify: `docs/hardening.md`

**Interfaces:** none (docs only).

- [ ] **Step 1: Update the README integration flow**

In `README.md`, locate the section describing the session handoff / `connect-session` contract. Replace the description of passing `sw-context-token` directly to `/laioutr/connect-session` with the two-step protocol:

```markdown
### Session handoff

The context token is never placed in a browser URL. Handoff is a two-step exchange:

1. **Mint (server-to-server).** The laioutr backend, holding the `sw-context-token`,
   calls `POST /store-api/laioutr/session-handoff` with the sales-channel access key
   (`sw-access-key` header) and the context token (`sw-context-token` header). Body:

   ```json
   {
     "login-success-callback": "https://<allowed-domain>/login",
     "logout-success-callback": "https://<allowed-domain>/logout",
     "redirect-route": "frontend.checkout.cart.page"
   }
   ```

   The callbacks are validated against the allowed-callback-domains configuration.
   Response: `{ "code": "<opaque single-use code>" }`. The code is valid for 60 seconds
   and can be redeemed once.

2. **Redeem (browser redirect).** Redirect the browser to
   `GET /laioutr/connect-session?code=<code>`. The plugin installs the context into the
   storefront session, regenerates the session id, and redirects to the stored route so
   the shopper lands on the checkout with their basket.
```

- [ ] **Step 2: Mark hardening item #1 as addressed**

In `docs/hardening.md`, under "Session and callback protocol", update the first two bullets to reflect that the signed/single-use state and the context-token query contract are now implemented via the one-time code exchange (reference the spec `docs/superpowers/specs/2026-07-16-session-handoff-hardening-design.md`). Leave the remaining, still-open bullets (rate limits, audit events, HTTPS policy) intact.

- [ ] **Step 3: Commit**

```bash
git add README.md docs/hardening.md
git commit -m "docs: document one-time code session handoff protocol"
```

---

## Final verification

- [ ] **Run the full suite:**
  `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpunit`
  Expected: all tests pass.
- [ ] **Static analysis:** `docker compose exec web composer --working-dir custom/plugins/LaioutrConnector phpstan` — no new errors.
- [ ] **Manual verification (implementation-time check from the spec §9):** confirm Shopware 6.6/6.7 storefront login rotates the `sw-context-token`. If it does **not**, open a follow-up to rotate the context token explicitly after `CustomerLoginEvent`.

## Out of scope (separate specs / follow-ups)

- Clickjacking / `X-Frame-Options` → `frame-ancestors` (hardening #2).
- Redirect-allowlist / wildcard-semantics hardening (hardening #3).
- Rate limiting on connect-session and the mint route; privacy-safe audit events; bespoke fail-closed error page (currently a `400`).
- Full Store-API HTTP integration test for the mint route with a real access key (unit + wiring coverage only here).
- Integration test that logs a customer in and then redeems, asserting the authenticated session context is overwritten. This is safe by construction — `ConnectController` overwrites the context token unconditionally with no "refuse when logged in" branch (spec §3 product decision), and the happy-path redeem test already exercises the overwrite mechanism — so it is deferred to a browser E2E rather than fragile in-suite login scaffolding.
