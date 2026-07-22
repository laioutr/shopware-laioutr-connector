<?php

declare(strict_types=1);

namespace Laioutr\Connector\Embedded\Business;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class RouteAllowlist
{
    public const CONFIG_KEY_ADDITIONAL_ROUTES = 'LaioutrConnector.config.lockdownAdditionalAllowedRoutes';

    /**
     * Request-path prefixes that stay reachable under lockdown regardless of route name.
     * Most storefront AJAX fragments live under `/widgets/*` but with inconsistent route names
     * (`frontend.cms.*`, `frontend.menu.offcanvas`, `widgets.*`, …), so they are matched by path.
     *
     * @var list<string>
     */
    private const ALLOWED_PATH_PREFIXES = [
        '/widgets/',
    ];

    /**
     * Route-name prefixes that stay reachable under lockdown: the cart, checkout, account, and
     * the plugin's own session routes, plus `widgets.*` AJAX fragments whose path is not under
     * `/widgets/` (e.g. `widgets.quickview.minimal` at `/quickview/{productId}`).
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
        'frontend.checkout.',
        'frontend.account.',
        'frontend.cart.',
        'frontend.laioutr.',
        'widgets.',
    ];

    /**
     * Exact route names that stay reachable: the error and maintenance pages
     * and the cookie-settings offcanvas the allowed flows depend on. Route
     * names are case-sensitive and must match Shopware's exactly.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'error',
        'frontend.cookie.offcanvas',
        'frontend.maintenance.page',
        'frontend.maintenance.singlepage',
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isAllowed(string $route, string $path, ?string $salesChannelId = null): bool
    {
        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

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
