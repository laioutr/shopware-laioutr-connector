<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Integration;

use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Laioutr\Connector\Session\Integration\AuthBridgeNotifier;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class AuthBridgeNotifierTest extends TestCase
{
    public function testScheduleLoginMintsCodeAndAppendsBothParams(): void
    {
        $codeService = $this->createStub(SessionHandoffCodeService::class);
        $codeService->method('generateCode')->willReturn('minted-code');

        $store = $this->createMock(SessionHandoffStore::class);
        $store->expects(static::once())->method('issue')->with(
            'minted-code',
            'ctx-token',
            'sales-channel-id',
            null,
            null,
            null,
        );

        $notifier = new AuthBridgeNotifier($codeService, $store);

        $request = new Request();
        $notifier->scheduleLogin($request, 'ctx-token', 'sales-channel-id', 'frontend.account.login');

        $event = $this->responseEvent($request, new RedirectResponse('/account'));
        $notifier->applyToResponse($event);

        static::assertSame(
            '/account?laioutr-auth-from=frontend.account.login&laioutr-auth-code=minted-code',
            $event->getResponse()->headers->get('Location'),
        );
    }

    public function testScheduleLogoutAppendsFromOnly(): void
    {
        $notifier = new AuthBridgeNotifier(
            $this->createStub(SessionHandoffCodeService::class),
            $this->createStub(SessionHandoffStore::class),
        );

        $request = new Request();
        $notifier->scheduleLogout($request, 'frontend.account.logout');

        $event = $this->responseEvent($request, new RedirectResponse('/'));
        $notifier->applyToResponse($event);

        static::assertSame(
            '/?laioutr-auth-from=frontend.account.logout',
            $event->getResponse()->headers->get('Location'),
        );
    }

    public function testApplyToResponseIgnoresNonRedirect(): void
    {
        $notifier = new AuthBridgeNotifier(
            $this->createStub(SessionHandoffCodeService::class),
            $this->createStub(SessionHandoffStore::class),
        );

        $request = new Request();
        $notifier->scheduleLogout($request, 'frontend.account.logout');

        $response = new Response('body');
        $event = $this->responseEvent($request, $response);
        $notifier->applyToResponse($event);

        static::assertSame($response, $event->getResponse());
        static::assertSame('body', $event->getResponse()->getContent());
    }

    public function testApplyToResponseNoopWithoutScheduledAuth(): void
    {
        $notifier = new AuthBridgeNotifier(
            $this->createStub(SessionHandoffCodeService::class),
            $this->createStub(SessionHandoffStore::class),
        );

        $event = $this->responseEvent(new Request(), new RedirectResponse('/account'));
        $notifier->applyToResponse($event);

        static::assertSame('/account', $event->getResponse()->headers->get('Location'));
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
