<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Storefront\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Laioutr\Connector\Session\Integration\SessionStorage;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ConnectController
{
    public function __construct(
        private readonly DomainWhitelistValidator $domainWhitelistValidator,
        private readonly SessionStorage $sessionStorage,
        private readonly SessionHandoffStore $sessionHandoffStore,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(
        path: '/laioutr/connect-session',
        name: 'frontend.laioutr.connect-session',
        methods: ['GET'],
    )]
    public function connectSession(Request $request, SalesChannelContext $context): Response
    {
        $code = $this->getRequiredQueryParameter($request, 'code');

        $handoff = $this->sessionHandoffStore->redeem($code);
        if ($handoff === null) {
            throw new BadRequestHttpException('Invalid or expired handoff code');
        }

        if (!hash_equals($handoff->salesChannelId, $context->getSalesChannelId())) {
            throw new BadRequestHttpException('Handoff was issued for a different sales channel');
        }

        foreach ([$handoff->loginSuccessCallback, $handoff->logoutSuccessCallback] as $callback) {
            if ($callback !== null && !$this->domainWhitelistValidator->isValidUrl($callback)) {
                throw new BadRequestHttpException('Callback domain is not allowed');
            }
        }

        if ($handoff->redirectRoute === null) {
            throw new BadRequestHttpException('Handoff is missing a redirect route');
        }

        // Resolve the redirect target before mutating the session, so an
        // unknown route fails closed with a 400 instead of leaving the session
        // rewritten behind a 500.
        try {
            $redirectUrl = $this->urlGenerator->generate($handoff->redirectRoute);
        } catch (RouteNotFoundException $exception) {
            throw new BadRequestHttpException('Handoff redirect route is not registered', $exception);
        }

        $this->sessionStorage->setContextToken($handoff->contextToken);
        if ($handoff->loginSuccessCallback !== null) {
            $this->sessionStorage->setLoginSuccessCallback($handoff->loginSuccessCallback);
        }
        if ($handoff->logoutSuccessCallback !== null) {
            $this->sessionStorage->setLogoutSuccessCallback($handoff->logoutSuccessCallback);
        }
        $this->sessionStorage->regenerate();

        return new RedirectResponse($redirectUrl, Response::HTTP_FOUND);
    }

    private function getRequiredQueryParameter(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            throw new BadRequestHttpException(sprintf('Query parameter "%s" must be a non-empty string', $name));
        }

        return $value;
    }
}
