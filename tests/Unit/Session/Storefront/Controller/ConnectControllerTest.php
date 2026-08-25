<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Storefront\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Integration\SessionHandoff;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Laioutr\Connector\Session\Integration\SessionStorage;
use Laioutr\Connector\Session\Storefront\Controller\ConnectController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ConnectControllerTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0189a1b2c3d44e5f8a9b0c1d2e3f4a5b';

    public function testRedemptionClearsReturnTargetsAHandoffDoesNotCarry(): void
    {
        $sessionStorage = $this->createMock(SessionStorage::class);
        $sessionStorage->expects(static::once())->method('setFinishSuccessCallback')->with(null);
        $sessionStorage->expects(static::once())->method('setCheckoutCallback')->with(null);

        $this->connect($this->handoff(null, null), $sessionStorage);
    }

    public function testRedemptionStoresTheReturnTargetsAHandoffCarries(): void
    {
        $sessionStorage = $this->createMock(SessionStorage::class);
        $sessionStorage->expects(static::once())
            ->method('setFinishSuccessCallback')
            ->with('http://localhost/thank-you');
        $sessionStorage->expects(static::once())
            ->method('setCheckoutCallback')
            ->with('http://localhost/checkout');

        $this->connect(
            $this->handoff('http://localhost/thank-you', 'http://localhost/checkout'),
            $sessionStorage,
        );
    }

    public function testRedirectIsGeneratedWithTheHandoffRouteParameters(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(static::once())
            ->method('generate')
            ->with('frontend.account.edit-order.page', ['orderId' => 'ord1'])
            ->willReturn('/account/order/edit/ord1');

        $handoff = new SessionHandoff(
            'ctx-token',
            self::SALES_CHANNEL_ID,
            null,
            null,
            'frontend.account.edit-order.page',
            null,
            null,
            ['orderId' => 'ord1'],
        );

        $response = $this->connect($handoff, $this->createMock(SessionStorage::class), $urlGenerator);

        static::assertSame('/account/order/edit/ord1', $response->headers->get('Location'));
    }

    private function handoff(?string $finishCallback, ?string $checkoutCallback): SessionHandoff
    {
        return new SessionHandoff(
            'ctx-token',
            self::SALES_CHANNEL_ID,
            null,
            null,
            'frontend.checkout.confirm.page',
            $finishCallback,
            $checkoutCallback,
        );
    }

    private function connect(
        SessionHandoff $handoff,
        SessionStorage $sessionStorage,
        ?UrlGeneratorInterface $urlGenerator = null,
    ): \Symfony\Component\HttpFoundation\Response {
        $validator = $this->createStub(DomainWhitelistValidator::class);
        $validator->method('isValidUrl')->willReturn(true);

        $store = $this->createStub(SessionHandoffStore::class);
        $store->method('redeem')->willReturn($handoff);

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/checkout/confirm');
        }

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $controller = new ConnectController($validator, $sessionStorage, $store, $urlGenerator);

        return $controller->connectSession(new Request(['code' => 'a-code']), $context);
    }
}
