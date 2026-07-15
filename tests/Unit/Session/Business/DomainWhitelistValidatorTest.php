<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Business;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class DomainWhitelistValidatorTest extends TestCase
{
    #[DataProvider('urlProvider')]
    public function testConfiguredDomains(string $configuration, string $url, bool $expected): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService
            ->method('getString')
            ->with(DomainWhitelistValidator::CONFIG_KEY)
            ->willReturn($configuration);

        $validator = new DomainWhitelistValidator($systemConfigService);

        static::assertSame($expected, $validator->isValidUrl($url));
    }

    public static function urlProvider(): iterable
    {
        yield 'exact domain' => ['example.com', 'https://example.com/callback', true];
        yield 'wildcard subdomain' => ['*.example.com', 'https://shop.example.com/callback', true];
        yield 'wildcard excludes apex' => ['*.example.com', 'https://example.com/callback', false];
        yield 'wildcard treats dots literally' => ['*.example.com', 'https://shop-example.com/callback', false];
        yield 'multiple lines ignore blanks' => ["\nexample.com\r\nlocalhost\n", 'http://localhost/callback', true];
        yield 'empty configuration fails closed' => ['', 'https://example.com/callback', false];
        yield 'hostless URL' => ['example.com', '/relative/path', false];
        yield 'malformed URL' => ['example.com', 'https:///callback', false];
        yield 'unconfigured domain' => ['example.com', 'https://attacker.example/callback', false];
    }
}
