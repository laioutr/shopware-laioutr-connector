<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Storefront\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Integration\SessionHandoff;
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
    private const ORDER_ROUTE = 'frontend.checkout.finish.order';

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

        $handoff = $this->redeem($code, $context);

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

        $this->installSession($handoff);

        return new RedirectResponse($redirectUrl, Response::HTTP_FOUND);
    }

    /**
     * Install a session for an order submitted from the top-level window, then forward the
     * submission to Shopware's own order route.
     *
     * The embedded confirm form targets the top-level window so redirect-based payment
     * providers are never framed: they refuse to render inside one, and once framed they
     * cannot navigate back out. That top-level request carries no storefront session when
     * laioutr and the storefront sit on different registrable domains, so the form brings a
     * handoff code along to establish one here.
     */
    #[Route(
        path: '/laioutr/checkout-order',
        name: 'frontend.laioutr.checkout-order',
        methods: ['POST'],
    )]
    public function checkoutOrder(Request $request, SalesChannelContext $context): Response
    {
        $code = $this->getRequiredBodyParameter($request, 'code');

        $handoff = $this->redeem($code, $context);

        $orderUrl = $this->urlGenerator->generate(self::ORDER_ROUTE);

        $this->installSession($handoff);

        // 307 preserves both method and body, so the confirm form's fields reach the order
        // route unchanged. A 302 or 303 would replay it as a GET, which that route rejects.
        return new RedirectResponse($orderUrl, Response::HTTP_TEMPORARY_REDIRECT);
    }

    /**
     * Redeem a single-use code and check it may act on this request: issued for this sales
     * channel, and carrying callbacks whose domains are still allowed. Performs no session
     * mutation, so a caller can resolve its redirect target first and fail closed.
     */
    private function redeem(string $code, SalesChannelContext $context): SessionHandoff
    {
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

        return $handoff;
    }

    private function installSession(SessionHandoff $handoff): void
    {
        $this->sessionStorage->setContextToken($handoff->contextToken);
        if ($handoff->loginSuccessCallback !== null) {
            $this->sessionStorage->setLoginSuccessCallback($handoff->loginSuccessCallback);
        }
        if ($handoff->logoutSuccessCallback !== null) {
            $this->sessionStorage->setLogoutSuccessCallback($handoff->logoutSuccessCallback);
        }
        $this->sessionStorage->regenerate();
    }

    private function getRequiredQueryParameter(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            throw new BadRequestHttpException(sprintf('Query parameter "%s" must be a non-empty string', $name));
        }

        return $value;
    }

    private function getRequiredBodyParameter(Request $request, string $name): string
    {
        $value = $request->request->all()[$name] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            throw new BadRequestHttpException(sprintf('Parameter "%s" must be a non-empty string', $name));
        }

        return $value;
    }
}
