<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Storefront\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class CookieRedirectController
{
    public function __construct(
        private readonly DomainWhitelistValidator $domainWhitelistValidator,
    ) {
    }

    #[Route(
        path: '/laioutr/cookie-bridge',
        name: 'frontend.laioutr.cookie-bridge',
        methods: ['GET'],
    )]
    public function cookieRedirect(Request $request): Response
    {
        $redirectUrl = $request->query->all()['redirect-route'] ?? null;

        if (!\is_string($redirectUrl) || trim($redirectUrl) === '') {
            throw new \InvalidArgumentException('Query parameter "redirect-route" must be a non-empty string');
        }

        if (!$this->domainWhitelistValidator->isValidUrl($redirectUrl)) {
            throw new \InvalidArgumentException('Given redirect-route is not of a valid domain');
        }

        return new RedirectResponse($redirectUrl, Response::HTTP_FOUND);
    }
}
