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
