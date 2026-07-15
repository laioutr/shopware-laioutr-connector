<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Business;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class DomainWhitelistValidator
{
    public const CONFIG_KEY = 'LaioutrConnector.config.callbackDomainWildcard';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isValidUrl(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return false;
        }

        foreach ($this->getConfiguredDomainPatterns() as $pattern) {
            if (preg_match($pattern, $host) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getConfiguredDomainPatterns(): array
    {
        $configuredDomains = preg_split(
            '/\r\n|\r|\n/',
            $this->systemConfigService->getString(self::CONFIG_KEY),
        );

        if ($configuredDomains === false) {
            return [];
        }

        $patterns = [];

        foreach ($configuredDomains as $configuredDomain) {
            $configuredDomain = trim($configuredDomain);
            if ($configuredDomain === '') {
                continue;
            }

            $quotedDomain = preg_quote($configuredDomain, '/');
            $patterns[] = '/^' . str_replace('\\*', '.*', $quotedDomain) . '$/';
        }

        return $patterns;
    }
}
