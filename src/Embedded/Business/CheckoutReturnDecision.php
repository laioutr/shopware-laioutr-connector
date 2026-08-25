<?php

declare(strict_types=1);

namespace Laioutr\Connector\Embedded\Business;

/**
 * Where a checkout that left the frame should send the shopper, given how Shopware answered.
 *
 * Reaching the finish route at all means the payment succeeded — a failed finalize goes to the
 * payment token's error URL instead — so the finish route's own outcome is what distinguishes a
 * completed order from a failed one.
 */
class CheckoutReturnDecision
{
    public const FINISH_ROUTE = 'frontend.checkout.finish.page';
    public const EDIT_ORDER_ROUTE = 'frontend.account.edit-order.page';

    private const REGISTER_PATH = '/checkout/register';
    private const EDIT_ORDER_PATH = '/account/order/edit';

    public function decide(
        string $route,
        int $statusCode,
        ?string $location,
        ?string $orderId,
        ?string $finishTarget,
        ?string $checkoutTarget,
        bool $embedded,
        ?string $errorCode = null,
    ): ?string {
        if ($orderId === null) {
            return null;
        }

        if ($route === self::EDIT_ORDER_ROUTE) {
            // Un-embedded there is no frame to return to, and the storefront handles the retry in
            // full with its own header and footer.
            return $embedded ? $this->retryUrl($checkoutTarget, $orderId, $errorCode) : null;
        }

        if ($route !== self::FINISH_ROUTE) {
            return null;
        }

        if ($statusCode < 300) {
            return $this->finishUrl($finishTarget, $orderId);
        }

        if ($location !== null && str_contains($location, self::REGISTER_PATH)) {
            // The session did not survive the provider. The order exists and was paid.
            return $this->finishUrl($finishTarget, $orderId);
        }

        if ($location !== null && str_contains($location, self::EDIT_ORDER_PATH)) {
            return $embedded ? $this->retryUrl($checkoutTarget, $orderId, $errorCode) : null;
        }

        return null;
    }

    private function finishUrl(?string $target, string $orderId): ?string
    {
        return $target === null ? null : $target . $this->separator($target) . 'order=' . rawurlencode($orderId);
    }

    private function retryUrl(?string $target, string $orderId, ?string $errorCode): ?string
    {
        if ($target === null) {
            return null;
        }

        $url = $target . $this->separator($target) . 'retry-order=' . rawurlencode($orderId);

        return $errorCode === null ? $url : $url . '&error-code=' . rawurlencode($errorCode);
    }

    private function separator(string $url): string
    {
        return str_contains($url, '?') ? '&' : '?';
    }
}
