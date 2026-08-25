<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Integration;

use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class CallbackRedirector
{
    private const CALLBACK_URL_ATTRIBUTE = '_laioutr_callback_url';
    private const FROM_ATTRIBUTE = '_laioutr_callback_from';
    private const CODE_ATTRIBUTE = '_laioutr_callback_code';
    private const CARRY_RETURN_ATTRIBUTE = '_laioutr_callback_carry_return';

    public function __construct(
        private readonly SessionStorage $sessionStorage,
        private readonly SessionHandoffCodeService $codeService,
        private readonly SessionHandoffStore $store,
    ) {
    }

    /**
     * Schedule the post-login bounce to laioutr, carrying a single-use code for the rotated
     * context token. Shopware rotates that token on login, so without the code laioutr keeps
     * the pre-login one and reads the shopper as a guest.
     *
     * `$carryReturn` sends the shopper back to wherever Shopware was taking them. Set it
     * wherever the login is incidental to what the shopper was doing — a login inside checkout
     * must not end with them on laioutr.
     */
    public function scheduleLoginCallback(
        Request $request,
        string $contextToken,
        string $salesChannelId,
        string $from,
        bool $carryReturn,
    ): void {
        $callbackUrl = $this->sessionStorage->getLoginSuccessCallback();
        if ($callbackUrl === null) {
            return;
        }

        $code = $this->codeService->generateCode();
        $this->store->issue($code, $contextToken, $salesChannelId, null, null, null);
        $request->attributes->set(self::CODE_ATTRIBUTE, $code);

        $this->scheduleCallback($request, $callbackUrl, $from, $carryReturn);
    }

    /** No code: an absent code is how the far side is told to clear rather than adopt. */
    public function scheduleLogoutCallback(Request $request, string $from, bool $carryReturn): void
    {
        $this->scheduleCallback($request, $this->sessionStorage->getLogoutSuccessCallback(), $from, $carryReturn);
    }

    public function applyScheduledCallback(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $callbackUrl = $request->attributes->get(self::CALLBACK_URL_ATTRIBUTE);
        $from = $request->attributes->get(self::FROM_ATTRIBUTE);

        if (!\is_string($callbackUrl) || !\is_string($from)) {
            return;
        }

        $response = $event->getResponse();
        $returnTo = null;

        // Only a redirect names a destination worth returning to; a rendered page means the
        // shopper is already where they asked to be.
        if ($request->attributes->get(self::CARRY_RETURN_ATTRIBUTE) === true && $response instanceof RedirectResponse) {
            $returnTo = $this->toAbsoluteUrl($request, $response->getTargetUrl());
        }

        $code = $request->attributes->get(self::CODE_ATTRIBUTE);

        $event->setResponse(new RedirectResponse($this->buildRedirectUrl(
            $callbackUrl,
            $from,
            \is_string($code) ? $code : null,
            $returnTo,
        )));
    }

    public function buildRedirectUrl(
        string $callbackUrl,
        string $from,
        ?string $code = null,
        ?string $returnTo = null,
    ): string {
        $fragmentPosition = strpos($callbackUrl, '#');
        $fragment = '';

        if ($fragmentPosition !== false) {
            $fragment = substr($callbackUrl, $fragmentPosition);
            $callbackUrl = substr($callbackUrl, 0, $fragmentPosition);
        }

        $separator = str_contains($callbackUrl, '?') ? '&' : '?';
        if (str_ends_with($callbackUrl, '?') || str_ends_with($callbackUrl, '&')) {
            $separator = '';
        }

        $params = ['from' => $from];
        if ($code !== null) {
            $params['code'] = $code;
        }
        if ($returnTo !== null) {
            $params['return-to'] = $returnTo;
        }

        return $callbackUrl . $separator . http_build_query(
            $params,
            '',
            '&',
            \PHP_QUERY_RFC3986,
        ) . $fragment;
    }

    private function toAbsoluteUrl(Request $request, string $target): string
    {
        if (preg_match('#^https?://#i', $target) === 1) {
            return $target;
        }

        return $request->getSchemeAndHttpHost() . '/' . ltrim($target, '/');
    }

    private function scheduleCallback(Request $request, ?string $callbackUrl, string $from, bool $carryReturn): void
    {
        if ($callbackUrl === null) {
            return;
        }

        $request->attributes->set(self::CALLBACK_URL_ATTRIBUTE, $callbackUrl);
        $request->attributes->set(self::FROM_ATTRIBUTE, $from);
        $request->attributes->set(self::CARRY_RETURN_ATTRIBUTE, $carryReturn);
    }
}
