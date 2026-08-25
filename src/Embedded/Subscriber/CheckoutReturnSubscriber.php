<?php

declare(strict_types=1);

namespace Laioutr\Connector\Embedded\Subscriber;

use Laioutr\Connector\Embedded\Business\CheckoutReturnDecision;
use Laioutr\Connector\Embedded\EmbeddedConfig;
use Laioutr\Connector\Session\Business\ReturnTargetResolver;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Returns a shopper whose checkout left the frame to Laioutr, on both exits from the order route.
 *
 * This has to run at RESPONSE rather than REQUEST: the finish route redirects to the account's
 * order-edit page when the payment failed, and that outcome is only known once the page has loaded.
 */
class CheckoutReturnSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CheckoutReturnDecision $decision,
        private readonly ReturnTargetResolver $returnTargetResolver,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * @return array<string, array<int, array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        // After the auth bridge's scheduled callback (-1) and the frame-options listener (-2), so
        // a callback it already scheduled still wins.
        return [KernelEvents::RESPONSE => [['onResponse', -8]]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if ($route !== CheckoutReturnDecision::FINISH_ROUTE && $route !== CheckoutReturnDecision::EDIT_ORDER_ROUTE) {
            return;
        }

        $orderId = $this->resolveOrderId($request, $route);
        if ($orderId === null) {
            return;
        }

        // Resolving a target reads the storefront session, and this listener sees every main
        // response — store-api requests among them, which carry no session at all.
        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        $salesChannelId = \is_string($salesChannelId) ? $salesChannelId : null;

        $response = $event->getResponse();

        $target = $this->decision->decide(
            $route,
            $response->getStatusCode(),
            $response->headers->get('Location'),
            $orderId,
            $this->returnTargetResolver->resolveFinishTarget($salesChannelId),
            $this->returnTargetResolver->resolveCheckoutTarget($salesChannelId),
            $this->systemConfigService->getBool(EmbeddedConfig::EMBEDDED_MODE, $salesChannelId),
            $this->resolveErrorCode($request, $response),
        );

        if ($target === null) {
            return;
        }

        $event->setResponse(new RedirectResponse($target, Response::HTTP_FOUND));
    }

    /**
     * The finish route carries the id in the query; the order-edit route carries it in the path.
     * Reading it from the request rather than the session keeps it available when the session did
     * not survive the payment provider.
     */
    private function resolveOrderId(Request $request, string $route): ?string
    {
        if ($route === CheckoutReturnDecision::EDIT_ORDER_ROUTE) {
            return $this->stringOrNull($request->attributes->get('orderId'));
        }

        return $this->stringOrNull($request->query->all()['orderId'] ?? null);
    }

    /**
     * A shopper the provider bounced back arrives at the order-edit route with the code in the
     * query. One whose payment failed during finalize never gets there: Shopware answers the finish
     * route with a redirect that carries the code, and this listener replaces that redirect.
     */
    private function resolveErrorCode(Request $request, Response $response): ?string
    {
        $fromQuery = $this->stringOrNull($request->query->all()['error-code'] ?? null);
        if ($fromQuery !== null) {
            return $fromQuery;
        }

        $location = $response->headers->get('Location');
        if ($location === null) {
            return null;
        }

        $query = parse_url($location, \PHP_URL_QUERY);
        if (!\is_string($query)) {
            return null;
        }

        parse_str($query, $parsed);

        return $this->stringOrNull($parsed['error-code'] ?? null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
