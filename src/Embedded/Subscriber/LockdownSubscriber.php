<?php

declare(strict_types=1);

namespace Laioutr\Connector\Embedded\Subscriber;

use Laioutr\Connector\Embedded\Business\RouteAllowlist;
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
    public const CONFIG_KEY_EMBEDDED_MODE = 'LaioutrConnector.config.embeddedModeEnabled';

    private const REDIRECT_ROUTE = 'frontend.checkout.cart.page';

    public function __construct(
        private readonly RouteAllowlist $routeAllowlist,
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

        if (!$this->systemConfigService->getBool(self::CONFIG_KEY_EMBEDDED_MODE, $salesChannelId)) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route) || $this->routeAllowlist->isAllowed($route, $salesChannelId)) {
            return;
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate(self::REDIRECT_ROUTE)),
        );
    }
}
