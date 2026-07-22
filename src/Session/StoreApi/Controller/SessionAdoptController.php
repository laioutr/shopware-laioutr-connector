<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\StoreApi\Controller;

use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class SessionAdoptController
{
    public function __construct(
        private readonly SessionHandoffStore $store,
    ) {
    }

    #[Route(
        path: '/store-api/laioutr/session-adopt',
        name: 'store-api.laioutr.session-adopt',
        methods: ['POST'],
    )]
    public function adopt(Request $request, SalesChannelContext $context): JsonResponse
    {
        $code = $request->request->get('code');
        if (!\is_string($code) || trim($code) === '') {
            throw new BadRequestHttpException('Parameter "code" must be a non-empty string');
        }

        $handoff = $this->store->redeem($code);
        if ($handoff === null) {
            throw new BadRequestHttpException('Invalid or expired handoff code');
        }

        if (!hash_equals($handoff->salesChannelId, $context->getSalesChannelId())) {
            throw new BadRequestHttpException('Handoff was issued for a different sales channel');
        }

        return new JsonResponse(['context-token' => $handoff->contextToken]);
    }
}
