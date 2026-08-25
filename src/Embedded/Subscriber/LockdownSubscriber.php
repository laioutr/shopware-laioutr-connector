<?php

declare(strict_types=1);

namespace Laioutr\Connector\Embedded\Subscriber;

use Laioutr\Connector\Embedded\Business\RouteBlocklist;
use Laioutr\Connector\Embedded\EmbeddedConfig;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class LockdownSubscriber implements EventSubscriberInterface
{
    private const REDIRECT_ROUTE = 'frontend.checkout.cart.page';

    public function __construct(
        private readonly RouteBlocklist $routeBlocklist,
        private readonly SystemConfigService $systemConfigService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 4 runs after Symfony's RouterListener (priority 32), so
        // `_route` and `_routeScope` are populated, and before the controller.
        return [
            KernelEvents::REQUEST => [['onRequest', 4]],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $scopes = $request->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, []);
        if (!\is_array($scopes) || !\in_array(StorefrontRouteScope::ID, $scopes, true)) {
            return;
        }

        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        $salesChannelId = \is_string($salesChannelId) ? $salesChannelId : null;

        if (!$this->isLockdownEnabled($salesChannelId)) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route) || !$this->routeBlocklist->isBlocked($route, $salesChannelId)) {
            return;
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate(self::REDIRECT_ROUTE)),
        );
    }

    /**
     * `config.xml` defaults are written to `system_config` only when the plugin installs, so a
     * field introduced in a later version has no stored value on an existing install — and
     * `getBool()` cannot tell that apart from an explicit `false`. Fall back to the documented
     * default rather than silently dropping lockdown on upgrade.
     */
    private function isLockdownEnabled(?string $salesChannelId): bool
    {
        $value = $this->systemConfigService->get(EmbeddedConfig::LOCKDOWN, $salesChannelId);

        return $value === null || (bool) $value;
    }
}
