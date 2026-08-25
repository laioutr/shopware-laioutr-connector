<?php

declare(strict_types=1);

namespace Laioutr\Connector;

use Doctrine\DBAL\Connection;
use Laioutr\Connector\Embedded\EmbeddedConfig;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class LaioutrConnector extends Plugin
{
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->seedLockdownFromEmbeddedMode();
    }

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

    /**
     * Lockdown used to be part of embedded mode. Now that it is its own setting, carry the old
     * behaviour across at every scope that had embedded mode stored — otherwise a sales channel
     * deliberately left un-embedded (a plain storefront alongside a headless one) would come out
     * of the upgrade with its content pages redirecting to the cart.
     *
     * `config.xml` defaults only apply on install, so nothing else writes this value for an
     * existing install. Scopes that already carry an explicit lockdown value are left alone.
     */
    private function seedLockdownFromEmbeddedMode(): void
    {
        $container = $this->container;
        if ($container === null) {
            return;
        }

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        /** @var SystemConfigService $systemConfigService */
        $systemConfigService = $container->get(SystemConfigService::class);

        $scopesWithLockdown = $connection->fetchFirstColumn(
            'SELECT LOWER(HEX(sales_channel_id)) FROM system_config WHERE configuration_key = :key',
            ['key' => EmbeddedConfig::LOCKDOWN],
        );

        $scopesWithEmbeddedMode = $connection->fetchFirstColumn(
            'SELECT LOWER(HEX(sales_channel_id)) FROM system_config WHERE configuration_key = :key',
            ['key' => EmbeddedConfig::EMBEDDED_MODE],
        );

        foreach ($scopesWithEmbeddedMode as $salesChannelId) {
            // NULL is the global scope; anything else is a sales-channel id.
            if ($salesChannelId !== null && !\is_string($salesChannelId)) {
                continue;
            }

            if (\in_array($salesChannelId, $scopesWithLockdown, true)) {
                continue;
            }

            $systemConfigService->set(
                EmbeddedConfig::LOCKDOWN,
                $systemConfigService->getBool(EmbeddedConfig::EMBEDDED_MODE, $salesChannelId),
                $salesChannelId,
            );
        }
    }
}
