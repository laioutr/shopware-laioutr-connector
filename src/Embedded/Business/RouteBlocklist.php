<?php

declare(strict_types=1);

namespace Laioutr\Connector\Embedded\Business;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class RouteBlocklist
{
    public const CONFIG_KEY_ADDITIONAL_ROUTES = 'LaioutrConnector.config.lockdownAdditionalBlockedRoutes';

    /**
     * Storefront pages Laioutr renders itself. Everything else stays reachable, because payment
     * plugins each register their own `frontend.*` namespace and an allowlist silently redirects
     * them to the cart — a broken checkout is a worse outcome than an obscure page leaking through.
     *
     * `frontend.cms.page` is deliberately absent: it serves `/widgets/cms/{id}`, which the confirm
     * page's terms and cancellation-policy modals fetch. `frontend.cms.page.full` is the standalone
     * render and is blocked instead.
     *
     * @var list<string>
     */
    private const BLOCKED_ROUTES = [
        'frontend.home.page',
        'frontend.navigation.page',
        'frontend.detail.page',
        'frontend.search.page',
        'frontend.search.suggest',
        'frontend.landing.page',
        'frontend.cms.page.full',
        'frontend.wishlist.page',
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isBlocked(string $route, ?string $salesChannelId = null): bool
    {
        return \in_array($route, self::BLOCKED_ROUTES, true)
            || \in_array($route, $this->getAdditionalBlockedRoutes($salesChannelId), true);
    }

    /**
     * @return list<string>
     */
    private function getAdditionalBlockedRoutes(?string $salesChannelId): array
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
