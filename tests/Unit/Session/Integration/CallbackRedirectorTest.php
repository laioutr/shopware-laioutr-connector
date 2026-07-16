<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Integration;

use Laioutr\Connector\Session\Integration\CallbackRedirector;
use Laioutr\Connector\Session\Integration\SessionStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class CallbackRedirectorTest extends TestCase
{
    public function testBuildsEncodedCallbackUrlWithoutDisclosingContextToken(): void
    {
        $sessionStorage = $this->createStub(SessionStorage::class);
        $redirector = new CallbackRedirector($sessionStorage);

        static::assertSame(
            'https://example.com/callback?from=frontend.account%20route',
            $redirector->buildRedirectUrl('https://example.com/callback', 'frontend.account route'),
        );
    }

    public function testPreservesExistingQueryAndFragment(): void
    {
        $sessionStorage = $this->createStub(SessionStorage::class);
        $redirector = new CallbackRedirector($sessionStorage);

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
        $sessionStorage = $this->createStub(SessionStorage::class);
        $sessionStorage
            ->method('getLoginSuccessCallback')
            ->willReturn('https://example.com/callback');

        $redirector = new CallbackRedirector($sessionStorage);
        $request = new Request();
        $redirector->scheduleLoginCallback($request, 'frontend.account.home.page');

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('original'),
        );

        $redirector->applyScheduledCallback($event);

        static::assertSame(Response::HTTP_FOUND, $event->getResponse()->getStatusCode());
        static::assertSame(
            'https://example.com/callback?from=frontend.account.home.page',
            $event->getResponse()->headers->get('Location'),
        );
    }

    public function testDoesNotChangeResponseWithoutCallback(): void
    {
        $sessionStorage = $this->createStub(SessionStorage::class);
        $redirector = new CallbackRedirector($sessionStorage);
        $response = new Response('original');
        $request = new Request();

        $redirector->scheduleLoginCallback($request, 'frontend.account.home.page');

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $redirector->applyScheduledCallback($event);

        static::assertSame($response, $event->getResponse());
    }
}
