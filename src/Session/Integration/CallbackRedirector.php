<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Integration;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class CallbackRedirector
{
    private const CALLBACK_URL_ATTRIBUTE = '_laioutr_callback_url';
    private const FROM_ATTRIBUTE = '_laioutr_callback_from';

    public function __construct(
        private readonly SessionStorage $sessionStorage,
    ) {
    }

    public function scheduleLoginCallback(Request $request, string $from): void
    {
        $this->scheduleCallback($request, $this->sessionStorage->getLoginSuccessCallback(), $from);
    }

    public function scheduleLogoutCallback(Request $request, string $from): void
    {
        $this->scheduleCallback($request, $this->sessionStorage->getLogoutSuccessCallback(), $from);
    }

    public function applyScheduledCallback(ResponseEvent $event): void
    {
        $callbackUrl = $event->getRequest()->attributes->get(self::CALLBACK_URL_ATTRIBUTE);
        $from = $event->getRequest()->attributes->get(self::FROM_ATTRIBUTE);

        if (!\is_string($callbackUrl) || !\is_string($from)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->buildRedirectUrl($callbackUrl, $from)));
    }

    public function buildRedirectUrl(string $callbackUrl, string $from): string
    {
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

        return $callbackUrl . $separator . http_build_query(
            ['from' => $from],
            '',
            '&',
            \PHP_QUERY_RFC3986,
        ) . $fragment;
    }

    private function scheduleCallback(Request $request, ?string $callbackUrl, string $from): void
    {
        if ($callbackUrl === null) {
            return;
        }

        $request->attributes->set(self::CALLBACK_URL_ATTRIBUTE, $callbackUrl);
        $request->attributes->set(self::FROM_ATTRIBUTE, $from);
    }
}
