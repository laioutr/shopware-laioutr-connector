<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Subscriber;

use Laioutr\Connector\Session\Integration\AuthBridgeNotifier;
use Laioutr\Connector\Session\Integration\CallbackRedirector;
use Laioutr\Connector\Session\Subscriber\AuthSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerLogoutEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AuthSubscriberTest extends TestCase
{
    public function testLoginInEmbeddedModeNotifiesBridge(): void
    {
        $notifier = $this->createMock(AuthBridgeNotifier::class);
        $notifier->expects(static::once())->method('scheduleLogin')
            ->with(static::isInstanceOf(Request::class), 'ctx-token', 'sc-id', 'frontend.account.login');

        $callback = $this->createMock(CallbackRedirector::class);
        $callback->expects(static::never())->method('scheduleLoginCallback');

        $subscriber = new AuthSubscriber(
            $this->requestStackWithRoute('frontend.account.login'),
            $callback,
            $notifier,
            $this->configService(true),
        );

        $subscriber->onLoginSuccess($this->loginEvent('ctx-token', 'sc-id', true));
    }

    public function testLoginOutsideEmbeddedModeUsesCallbackRedirector(): void
    {
        $notifier = $this->createMock(AuthBridgeNotifier::class);
        $notifier->expects(static::never())->method('scheduleLogin');

        $callback = $this->createMock(CallbackRedirector::class);
        $callback->expects(static::once())->method('scheduleLoginCallback')
            ->with(static::isInstanceOf(Request::class), 'frontend.account.login');

        $subscriber = new AuthSubscriber(
            $this->requestStackWithRoute('frontend.account.login'),
            $callback,
            $notifier,
            $this->configService(false),
        );

        $subscriber->onLoginSuccess($this->loginEvent('ctx-token', 'sc-id', true));
    }

    public function testLogoutInEmbeddedModeNotifiesBridge(): void
    {
        $notifier = $this->createMock(AuthBridgeNotifier::class);
        $notifier->expects(static::once())->method('scheduleLogout')
            ->with(static::isInstanceOf(Request::class), 'frontend.account.logout');

        $callback = $this->createMock(CallbackRedirector::class);
        $callback->expects(static::never())->method('scheduleLogoutCallback');

        $subscriber = new AuthSubscriber(
            $this->requestStackWithRoute('frontend.account.logout'),
            $callback,
            $notifier,
            $this->configService(true),
        );

        $subscriber->onLogoutSuccess($this->logoutEvent('sc-id'));
    }

    private function requestStackWithRoute(string $route): RequestStack
    {
        $request = new Request();
        $request->attributes->set('_route', $route);
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    private function configService(bool $embedded): SystemConfigService
    {
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getBool')->willReturn($embedded);

        return $config;
    }

    private function loginEvent(string $token, string $salesChannelId, bool $hasCustomer): CustomerLoginEvent
    {
        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($hasCustomer ? $this->createStub(CustomerEntity::class) : null);
        $context->method('getToken')->willReturn($token);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);

        $event = $this->createStub(CustomerLoginEvent::class);
        $event->method('getSalesChannelContext')->willReturn($context);

        return $event;
    }

    private function logoutEvent(string $salesChannelId): CustomerLogoutEvent
    {
        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);

        $event = $this->createStub(CustomerLogoutEvent::class);
        $event->method('getSalesChannelContext')->willReturn($context);

        return $event;
    }
}
