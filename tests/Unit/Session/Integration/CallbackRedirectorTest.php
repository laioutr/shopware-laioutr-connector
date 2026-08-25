<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Integration;

use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Laioutr\Connector\Session\Integration\CallbackRedirector;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Laioutr\Connector\Session\Integration\SessionStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class CallbackRedirectorTest extends TestCase
{
    public function testBuildsEncodedCallbackUrlWithoutDisclosingContextToken(): void
    {
        $redirector = $this->redirector($this->createStub(SessionStorage::class));

        static::assertSame(
            'https://example.com/callback?from=frontend.account%20route',
            $redirector->buildRedirectUrl('https://example.com/callback', 'frontend.account route'),
        );
    }

    public function testPreservesExistingQueryAndFragment(): void
    {
        $redirector = $this->redirector($this->createStub(SessionStorage::class));

        static::assertSame(
            'https://example.com/callback?existing=1&from=frontend.account.home.page#fragment',
            $redirector->buildRedirectUrl(
                'https://example.com/callback?existing=1#fragment',
                'frontend.account.home.page',
            ),
        );
    }

    public function testAppliesScheduledCallbackToKernelResponse(): void
    {
        $redirector = $this->redirector($this->loginStorage());

        $request = new Request();
        $redirector->scheduleLoginCallback($request, 'ctx-token', 'sales-channel-id', 'frontend.account.home.page', false);

        $event = $this->responseEvent($request, new Response('original'));
        $redirector->applyScheduledCallback($event);

        static::assertSame(Response::HTTP_FOUND, $event->getResponse()->getStatusCode());
        static::assertSame(
            'https://example.com/callback?from=frontend.account.home.page&code=minted-code',
            $event->getResponse()->headers->get('Location'),
        );
    }

    public function testDoesNotChangeResponseWithoutCallback(): void
    {
        $redirector = $this->redirector($this->createStub(SessionStorage::class));
        $response = new Response('original');
        $request = new Request();

        $redirector->scheduleLoginCallback($request, 'ctx-token', 'sales-channel-id', 'frontend.account.home.page', false);

        $event = $this->responseEvent($request, $response);
        $redirector->applyScheduledCallback($event);

        static::assertSame($response, $event->getResponse());
    }

    public function testScheduleLoginMintsCodeForTheRotatedToken(): void
    {
        $store = $this->createMock(SessionHandoffStore::class);
        $store->expects(static::once())->method('issue')->with(
            'minted-code',
            'ctx-token',
            'sales-channel-id',
            null,
            null,
            null,
        );

        $redirector = $this->redirector($this->loginStorage(), $store);

        $redirector->scheduleLoginCallback(new Request(), 'ctx-token', 'sales-channel-id', 'frontend.account.login', false);
    }

    public function testCarriesShopwareRedirectTargetAsReturnTo(): void
    {
        $redirector = $this->redirector($this->loginStorage());

        $request = Request::create('https://shop.example.com/account/login');
        $redirector->scheduleLoginCallback($request, 'ctx-token', 'sales-channel-id', 'frontend.account.login', true);

        $event = $this->responseEvent($request, new RedirectResponse('/checkout/confirm'));
        $redirector->applyScheduledCallback($event);

        static::assertSame(
            'https://example.com/callback?from=frontend.account.login&code=minted-code'
            . '&return-to=https%3A%2F%2Fshop.example.com%2Fcheckout%2Fconfirm',
            $event->getResponse()->headers->get('Location'),
        );
    }

    public function testKeepsAnAlreadyAbsoluteRedirectTarget(): void
    {
        $redirector = $this->redirector($this->loginStorage());

        $request = Request::create('https://shop.example.com/account/login');
        $redirector->scheduleLoginCallback($request, 'ctx-token', 'sales-channel-id', 'frontend.account.login', true);

        $event = $this->responseEvent($request, new RedirectResponse('https://shop.example.com/checkout/confirm'));
        $redirector->applyScheduledCallback($event);

        static::assertStringContainsString(
            'return-to=https%3A%2F%2Fshop.example.com%2Fcheckout%2Fconfirm',
            (string) $event->getResponse()->headers->get('Location'),
        );
    }

    public function testOmitsReturnToWhenNotCarrying(): void
    {
        $redirector = $this->redirector($this->loginStorage());

        $request = Request::create('https://shop.example.com/account/login');
        $redirector->scheduleLoginCallback($request, 'ctx-token', 'sales-channel-id', 'frontend.account.login', false);

        $event = $this->responseEvent($request, new RedirectResponse('/account'));
        $redirector->applyScheduledCallback($event);

        static::assertStringNotContainsString('return-to', (string) $event->getResponse()->headers->get('Location'));
    }

    public function testOmitsReturnToWhenResponseIsNotARedirect(): void
    {
        $redirector = $this->redirector($this->loginStorage());

        $request = Request::create('https://shop.example.com/account/login');
        $redirector->scheduleLoginCallback($request, 'ctx-token', 'sales-channel-id', 'frontend.account.login', true);

        $event = $this->responseEvent($request, new Response('rendered'));
        $redirector->applyScheduledCallback($event);

        static::assertStringNotContainsString('return-to', (string) $event->getResponse()->headers->get('Location'));
    }

    public function testScheduleLogoutAppendsNoCode(): void
    {
        $sessionStorage = $this->createStub(SessionStorage::class);
        $sessionStorage->method('getLogoutSuccessCallback')->willReturn('https://example.com/callback');

        $store = $this->createMock(SessionHandoffStore::class);
        $store->expects(static::never())->method('issue');

        $redirector = $this->redirector($sessionStorage, $store);

        $request = new Request();
        $redirector->scheduleLogoutCallback($request, 'frontend.account.logout', false);

        $event = $this->responseEvent($request, new Response('original'));
        $redirector->applyScheduledCallback($event);

        static::assertSame(
            'https://example.com/callback?from=frontend.account.logout',
            $event->getResponse()->headers->get('Location'),
        );
    }

    public function testMintsNoCodeWhenNoCallbackIsConfigured(): void
    {
        $store = $this->createMock(SessionHandoffStore::class);
        $store->expects(static::never())->method('issue');

        $redirector = $this->redirector($this->createStub(SessionStorage::class), $store);

        $redirector->scheduleLoginCallback(new Request(), 'ctx-token', 'sales-channel-id', 'frontend.account.login', true);
    }

    private function loginStorage(): SessionStorage
    {
        $sessionStorage = $this->createStub(SessionStorage::class);
        $sessionStorage->method('getLoginSuccessCallback')->willReturn('https://example.com/callback');

        return $sessionStorage;
    }

    private function redirector(SessionStorage $sessionStorage, ?SessionHandoffStore $store = null): CallbackRedirector
    {
        $codeService = $this->createStub(SessionHandoffCodeService::class);
        $codeService->method('generateCode')->willReturn('minted-code');

        return new CallbackRedirector(
            $sessionStorage,
            $codeService,
            $store ?? $this->createStub(SessionHandoffStore::class),
        );
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }
}
