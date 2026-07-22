<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Subscriber;

use Laioutr\Connector\Session\Integration\AuthBridgeNotifier;
use Laioutr\Connector\Session\Integration\CallbackRedirector;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerLogoutEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AuthSubscriber implements EventSubscriberInterface
{
    private const EMBEDDED_MODE_CONFIG_KEY = 'LaioutrConnector.config.embeddedModeEnabled';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CallbackRedirector $callbackRedirector,
        private readonly AuthBridgeNotifier $authBridgeNotifier,
        private readonly SystemConfigService $systemConfigService,
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
        $context = $event->getSalesChannelContext();
        if ($context->getCustomer() === null || !\is_string($route)) {
            return;
        }

        if ($this->isEmbedded($context->getSalesChannelId())) {
            $this->authBridgeNotifier->scheduleLogin(
                $request,
                $context->getToken(),
                $context->getSalesChannelId(),
                $route,
            );

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

        if ($this->isEmbedded($event->getSalesChannelContext()->getSalesChannelId())) {
            $this->authBridgeNotifier->scheduleLogout($request, $route);

            return;
        }

        $this->callbackRedirector->scheduleLogoutCallback($request, $route);
    }

    private function isEmbedded(string $salesChannelId): bool
    {
        return $this->systemConfigService->getBool(self::EMBEDDED_MODE_CONFIG_KEY, $salesChannelId);
    }
}
