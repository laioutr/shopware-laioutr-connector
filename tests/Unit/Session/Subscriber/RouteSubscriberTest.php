<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Subscriber;

use Laioutr\Connector\Session\Integration\CallbackRedirector;
use Laioutr\Connector\Session\Subscriber\RouteSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

class RouteSubscriberTest extends TestCase
{
    public function testAccountPageInEmbeddedModeDoesNotScheduleCallback(): void
    {
        $callback = $this->createMock(CallbackRedirector::class);
        $callback->expects(static::never())->method('scheduleLoginCallback');

        $subscriber = new RouteSubscriber($callback, $this->configService(true));
        $subscriber->onPageLoaded($this->pageEvent('frontend.account.home.page', 'sc-id'));
    }

    public function testAccountPageOutsideEmbeddedModeSchedulesCallback(): void
    {
        $callback = $this->createMock(CallbackRedirector::class);
        $callback->expects(static::once())->method('scheduleLoginCallback')
            ->with(static::isInstanceOf(Request::class), 'frontend.account.home.page');

        $subscriber = new RouteSubscriber($callback, $this->configService(false));
        $subscriber->onPageLoaded($this->pageEvent('frontend.account.home.page', 'sc-id'));
    }

    private function configService(bool $embedded): SystemConfigService
    {
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getBool')->willReturn($embedded);

        return $config;
    }

    private function pageEvent(string $route, string $salesChannelId): GenericPageLoadedEvent
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($this->createStub(CustomerEntity::class));
        $context->method('getSalesChannelId')->willReturn($salesChannelId);

        $event = $this->createStub(GenericPageLoadedEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('getSalesChannelContext')->willReturn($context);

        return $event;
    }
}
