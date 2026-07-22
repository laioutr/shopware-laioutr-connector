<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Integration;

use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Embedded-mode counterpart to CallbackRedirector. Instead of a 302 to an external
 * Laioutr origin (which would nest Laioutr inside its own iframe), it mints a single-use
 * handoff code for the rotated context token and appends {from, code} to Shopware's own
 * post-login/logout redirect. The next page render surfaces them on the bridge script, and
 * laioutr-embed.js emits laioutr:auth-changed. The token never leaves the server.
 */
class AuthBridgeNotifier implements EventSubscriberInterface
{
    private const FROM_ATTRIBUTE = '_laioutr_auth_from';
    private const CODE_ATTRIBUTE = '_laioutr_auth_code';

    public function __construct(
        private readonly SessionHandoffCodeService $codeService,
        private readonly SessionHandoffStore $store,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'applyToResponse',
        ];
    }

    public function scheduleLogin(Request $request, string $contextToken, string $salesChannelId, string $from): void
    {
        $code = $this->codeService->generateCode();
        $this->store->issue($code, $contextToken, $salesChannelId, null, null, null);

        $request->attributes->set(self::FROM_ATTRIBUTE, $from);
        $request->attributes->set(self::CODE_ATTRIBUTE, $code);
    }

    public function scheduleLogout(Request $request, string $from): void
    {
        $request->attributes->set(self::FROM_ATTRIBUTE, $from);
    }

    public function applyToResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $from = $request->attributes->get(self::FROM_ATTRIBUTE);
        if (!\is_string($from)) {
            return;
        }

        $response = $event->getResponse();
        if (!$response instanceof RedirectResponse) {
            return;
        }

        $params = ['laioutr-auth-from' => $from];
        $code = $request->attributes->get(self::CODE_ATTRIBUTE);
        if (\is_string($code)) {
            $params['laioutr-auth-code'] = $code;
        }

        $response->setTargetUrl($this->appendQueryParams($response->getTargetUrl(), $params));
    }

    /**
     * @param array<string, string> $params
     */
    private function appendQueryParams(string $url, array $params): string
    {
        $fragment = '';
        $hashPosition = strpos($url, '#');
        if ($hashPosition !== false) {
            $fragment = substr($url, $hashPosition);
            $url = substr($url, 0, $hashPosition);
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($params, '', '&', \PHP_QUERY_RFC3986) . $fragment;
    }
}
