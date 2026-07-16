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

        $container = $this->container;
        if ($container === null) {
            throw new \RuntimeException('Cannot uninstall Laioutr Connector: the DI container is not available.');
        }

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `laioutr_session_handoff`');
    }
}
