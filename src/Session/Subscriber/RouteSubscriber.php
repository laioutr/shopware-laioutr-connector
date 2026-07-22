<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Subscriber;

use Laioutr\Connector\Session\Integration\CallbackRedirector;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RouteSubscriber implements EventSubscriberInterface
{
    private const LOGGED_IN_REDIRECT_ROUTES = [
        'frontend.account.home.page',
        'frontend.account.profile.page',
        'frontend.account.profile.save',
        'frontend.account.profile.email.save',
        'frontend.account.profile.password.save',
        'frontend.account.profile.delete',
        'frontend.account.order.page',
        'frontend.account.order.cancel',
        'frontend.account.order.single.page',
        'widgets.account.order.detail',
        'frontend.account.edit-order.page',
        'frontend.account.edit-order.change-payment-method',
        'frontend.account.edit-order.update-order',
        'frontend.account.payment.page',
        'frontend.account.payment.save',
    ];

    private const EMBEDDED_MODE_CONFIG_KEY = 'LaioutrConnector.config.embeddedModeEnabled';

    public function __construct(
        private readonly CallbackRedirector $callbackRedirector,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            GenericPageLoadedEvent::class => 'onPageLoaded',
            KernelEvents::RESPONSE => [
                ['applyScheduledCallback', -1],
                ['removeFrameOptionsHeader', -2],
            ],
        ];
    }

    public function onPageLoaded(GenericPageLoadedEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $context = $event->getSalesChannelContext();

        if (
            $context->getCustomer() === null
            || $request->isXmlHttpRequest()
            || !\is_string($route)
            || !\in_array($route, self::LOGGED_IN_REDIRECT_ROUTES, true)
        ) {
            return;
        }

        // Embedded mode: account/order pages render inside the frame; the bridge (not a 302
        // to the laioutr origin) carries auth changes, so never schedule the external callback.
        if ($this->systemConfigService->getBool(self::EMBEDDED_MODE_CONFIG_KEY, $context->getSalesChannelId())) {
            return;
        }

        $this->callbackRedirector->scheduleLoginCallback($request, $route);
    }

    public function applyScheduledCallback(ResponseEvent $event): void
    {
        $this->callbackRedirector->applyScheduledCallback($event);
    }

    public function removeFrameOptionsHeader(ResponseEvent $event): void
    {
        $event->getResponse()->headers->remove(PlatformRequest::HEADER_FRAME_OPTIONS);
    }
}
