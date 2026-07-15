<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Subscriber;

use Laioutr\Connector\Session\Integration\CallbackRedirector;
use Shopware\Core\PlatformRequest;
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

    public function __construct(
        private readonly CallbackRedirector $callbackRedirector,
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

        if (
            $event->getSalesChannelContext()->getCustomer() === null
            || $request->isXmlHttpRequest()
            || !\is_string($route)
            || !\in_array($route, self::LOGGED_IN_REDIRECT_ROUTES, true)
        ) {
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
