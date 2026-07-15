<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Storefront\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Integration\SessionStorage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ConnectController
{
    public function __construct(
        private readonly DomainWhitelistValidator $domainWhitelistValidator,
        private readonly SessionStorage $sessionStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(
        path: '/laioutr/connect-session',
        name: 'frontend.laioutr.connect-session',
        methods: ['GET'],
    )]
    public function connectSession(Request $request): Response
    {
        $contextToken = $this->getRequiredQueryParameter($request, 'sw-context-token');
        $redirectRoute = $this->getRequiredQueryParameter($request, 'redirect-route');
        $loginSuccessCallback = $this->getRequiredQueryParameter($request, 'login-success-callback');
        $logoutSuccessCallback = $this->getRequiredQueryParameter($request, 'logout-success-callback');

        if (!$this->domainWhitelistValidator->isValidUrl($loginSuccessCallback)) {
            throw new \InvalidArgumentException('Given login-success-callback is not of a valid domain');
        }

        if (!$this->domainWhitelistValidator->isValidUrl($logoutSuccessCallback)) {
            throw new \InvalidArgumentException('Given logout-success-callback is not of a valid domain');
        }

        $this->sessionStorage->setContextToken($contextToken);
        $this->sessionStorage->setLoginSuccessCallback($loginSuccessCallback);
        $this->sessionStorage->setLogoutSuccessCallback($logoutSuccessCallback);

        return new RedirectResponse(
            $this->urlGenerator->generate($redirectRoute),
            Response::HTTP_FOUND,
        );
    }

    private function getRequiredQueryParameter(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('Query parameter "%s" must be a non-empty string', $name));
        }

        return $value;
    }
}
