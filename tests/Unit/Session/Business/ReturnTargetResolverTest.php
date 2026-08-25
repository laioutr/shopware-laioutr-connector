<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Business;

use Laioutr\Connector\Embedded\EmbeddedConfig;
use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Business\ReturnTargetResolver;
use Laioutr\Connector\Session\Integration\SessionStorage;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ReturnTargetResolverTest extends TestCase
{
    private function resolver(?string $sessionValue, string $configValue, bool $urlAllowed): ReturnTargetResolver
    {
        $storage = $this->createMock(SessionStorage::class);
        $storage->method('getFinishSuccessCallback')->willReturn($sessionValue);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('getString')->with(EmbeddedConfig::FINISH_FALLBACK_URL, null)->willReturn($configValue);

        $validator = $this->createMock(DomainWhitelistValidator::class);
        $validator->method('isValidUrl')->willReturn($urlAllowed);

        return new ReturnTargetResolver($storage, $config, $validator);
    }

    public function testSessionValueWins(): void
    {
        static::assertSame(
            'http://localhost/from-session',
            $this->resolver('http://localhost/from-session', 'http://localhost/from-config', true)
                ->resolveFinishTarget(null),
        );
    }

    public function testFallsBackToConfig(): void
    {
        static::assertSame(
            'http://localhost/from-config',
            $this->resolver(null, 'http://localhost/from-config', true)->resolveFinishTarget(null),
        );
    }

    public function testReturnsNullWhenNeitherIsSet(): void
    {
        static::assertNull($this->resolver(null, '', true)->resolveFinishTarget(null));
    }

    public function testRejectsDisallowedDomain(): void
    {
        static::assertNull(
            $this->resolver('https://not-allowed.example/x', '', false)->resolveFinishTarget(null),
        );
    }
}
