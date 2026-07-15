<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Subscriber;

use Laioutr\Connector\Session\Integration\CallbackRedirector;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerLogoutEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AuthSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CallbackRedirector $callbackRedirector,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerLoginEvent::class => 'onLoginSuccess',
            CustomerLogoutEvent::class => 'onLogoutSuccess',
        ];
    }

    public function onLoginSuccess(CustomerLoginEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || $request->isXmlHttpRequest()) {
            return;
        }

        $route = $request->attributes->get('_route');
        if ($event->getSalesChannelContext()->getCustomer() === null || !\is_string($route)) {
            return;
        }

        $this->callbackRedirector->scheduleLoginCallback($request, $route);
    }

    public function onLogoutSuccess(CustomerLogoutEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || $request->isXmlHttpRequest()) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route)) {
            return;
        }

        $this->callbackRedirector->scheduleLogoutCallback($request, $route);
    }
}
