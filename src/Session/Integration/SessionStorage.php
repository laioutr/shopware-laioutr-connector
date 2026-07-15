<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Integration;

use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class SessionStorage
{
    private const LOGIN_SUCCESS_CALLBACK_KEY = 'laioutr-login-success-callback';
    private const LOGOUT_SUCCESS_CALLBACK_KEY = 'laioutr-logout-success-callback';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getLoginSuccessCallback(): ?string
    {
        return $this->get(self::LOGIN_SUCCESS_CALLBACK_KEY);
    }

    public function setLoginSuccessCallback(string $loginSuccessCallback): void
    {
        $this->set(self::LOGIN_SUCCESS_CALLBACK_KEY, $loginSuccessCallback);
    }

    public function getLogoutSuccessCallback(): ?string
    {
        return $this->get(self::LOGOUT_SUCCESS_CALLBACK_KEY);
    }

    public function setLogoutSuccessCallback(string $logoutSuccessCallback): void
    {
        $this->set(self::LOGOUT_SUCCESS_CALLBACK_KEY, $logoutSuccessCallback);
    }

    public function getContextToken(): ?string
    {
        return $this->get(PlatformRequest::HEADER_CONTEXT_TOKEN);
    }

    public function setContextToken(string $contextToken): void
    {
        $this->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);
    }

    private function get(string $name): ?string
    {
        $value = $this->getSession()->get($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function set(string $name, string $value): void
    {
        $this->getSession()->set($name, $value);
    }

    private function getSession(): SessionInterface
    {
        return $this->requestStack->getSession();
    }
}
