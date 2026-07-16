<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\StoreApi\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class SessionHandoffController
{
    public function __construct(
        private readonly DomainWhitelistValidator $domainWhitelistValidator,
        private readonly SessionHandoffCodeService $codeService,
        private readonly SessionHandoffStore $store,
    ) {
    }

    #[Route(
        path: '/store-api/laioutr/session-handoff',
        name: 'store-api.laioutr.session-handoff',
        methods: ['POST'],
    )]
    public function issue(Request $request, SalesChannelContext $context): JsonResponse
    {
        $loginSuccessCallback = $this->getRequiredBodyParameter($request, 'login-success-callback');
        $logoutSuccessCallback = $this->getRequiredBodyParameter($request, 'logout-success-callback');
        $redirectRoute = $this->getRequiredBodyParameter($request, 'redirect-route');

        if (
            !$this->domainWhitelistValidator->isValidUrl($loginSuccessCallback)
            || !$this->domainWhitelistValidator->isValidUrl($logoutSuccessCallback)
        ) {
            throw new BadRequestHttpException('Callback domain is not allowed');
        }

        $code = $this->codeService->generateCode();

        $this->store->issue(
            $code,
            $context->getToken(),
            $context->getSalesChannelId(),
            $loginSuccessCallback,
            $logoutSuccessCallback,
            $redirectRoute,
        );

        return new JsonResponse(['code' => $code]);
    }

    private function getRequiredBodyParameter(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        if (!\is_string($value) || trim($value) === '') {
            throw new BadRequestHttpException(sprintf('Parameter "%s" must be a non-empty string', $name));
        }

        return $value;
    }
}
