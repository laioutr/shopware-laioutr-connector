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
