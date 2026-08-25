<?php

declare(strict_types=1);

namespace Laioutr\Connector\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1787700000AddHandoffReturnTargets extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787700000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `laioutr_session_handoff`
                ADD COLUMN `finish_success_callback` VARCHAR(2048) NULL AFTER `redirect_route`,
                ADD COLUMN `checkout_callback` VARCHAR(2048) NULL AFTER `finish_success_callback`,
                ADD COLUMN `redirect_route_params` JSON NULL AFTER `checkout_callback`;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
