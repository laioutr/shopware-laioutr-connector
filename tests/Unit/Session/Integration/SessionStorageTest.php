<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Integration;

use Laioutr\Connector\Session\Integration\SessionStorage;
use PHPUnit\Framework\TestCase;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class SessionStorageTest extends TestCase
{
    private Session $session;

    private SessionStorage $sessionStorage;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());

        $request = new Request();
        $request->setSession($this->session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $this->sessionStorage = new SessionStorage($requestStack);
    }

    public function testStoresAndReadsConnectorValues(): void
    {
        $this->sessionStorage->setContextToken('context-token');
        $this->sessionStorage->setLoginSuccessCallback('https://example.com/login');
        $this->sessionStorage->setLogoutSuccessCallback('https://example.com/logout');

        static::assertSame('context-token', $this->sessionStorage->getContextToken());
        static::assertSame('https://example.com/login', $this->sessionStorage->getLoginSuccessCallback());
        static::assertSame('https://example.com/logout', $this->sessionStorage->getLogoutSuccessCallback());
        static::assertSame('context-token', $this->session->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
    }

    public function testMissingAndUnexpectedValuesAreAbsent(): void
    {
        static::assertNull($this->sessionStorage->getLoginSuccessCallback());

        $this->session->set('laioutr-login-success-callback', ['not-a-string']);
        $this->session->set('laioutr-logout-success-callback', '');

        static::assertNull($this->sessionStorage->getLoginSuccessCallback());
        static::assertNull($this->sessionStorage->getLogoutSuccessCallback());
    }

    public function testRegenerateChangesSessionIdKeepingData(): void
    {
        $this->session->start();
        $this->session->set('keep', 'value');
        $before = $this->session->getId();

        $this->sessionStorage->regenerate();

        static::assertNotSame('', $this->session->getId());
        static::assertNotSame($before, $this->session->getId());
        static::assertSame('value', $this->session->get('keep'));
    }
}
