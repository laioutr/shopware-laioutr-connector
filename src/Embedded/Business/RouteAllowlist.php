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
