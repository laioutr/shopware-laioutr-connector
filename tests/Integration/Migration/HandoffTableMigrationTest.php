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
