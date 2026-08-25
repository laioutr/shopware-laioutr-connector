<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Business;

use Laioutr\Connector\Embedded\EmbeddedConfig;
use Laioutr\Connector\Session\Integration\SessionStorage;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Where to send a shopper who has finished, or failed to finish, a checkout that left the frame.
 *
 * The session value is written when a handoff is redeemed and describes the checkout in progress.
 * The configured fallback is the durable half: it survives a session the payment provider destroyed,
 * and it is the only source at all when the storefront is not embedded, because there is no section
 * to supply one.
 */
class ReturnTargetResolver
{
    public function __construct(
        private readonly SessionStorage $sessionStorage,
        private readonly SystemConfigService $systemConfigService,
        private readonly DomainWhitelistValidator $domainWhitelistValidator,
    ) {
    }

    public function resolveFinishTarget(?string $salesChannelId): ?string
    {
        return $this->resolve(
            $this->sessionStorage->getFinishSuccessCallback(),
            EmbeddedConfig::FINISH_FALLBACK_URL,
            $salesChannelId,
        );
    }

    public function resolveCheckoutTarget(?string $salesChannelId): ?string
    {
        return $this->resolve(
            $this->sessionStorage->getCheckoutCallback(),
            EmbeddedConfig::CHECKOUT_FALLBACK_URL,
            $salesChannelId,
        );
    }

    private function resolve(?string $sessionValue, string $configKey, ?string $salesChannelId): ?string
    {
        $candidate = $sessionValue;

        if ($candidate === null) {
            $configured = trim($this->systemConfigService->getString($configKey, $salesChannelId));
            $candidate = $configured === '' ? null : $configured;
        }

        if ($candidate === null || !$this->domainWhitelistValidator->isValidUrl($candidate)) {
            return null;
        }

        return $candidate;
    }
}
