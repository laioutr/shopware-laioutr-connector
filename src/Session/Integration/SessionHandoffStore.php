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
                    self::requireString($row['context_token']),
                    self::requireString($row['sales_channel_id']),
                    self::nullableString($row['login_success_callback']),
                    self::nullableString($row['logout_success_callback']),
                    self::nullableString($row['redirect_route']),
                );
            },
        );
    }

    private static function requireString(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string column value from laioutr_session_handoff.');
        }

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::requireString($value);
    }
}
