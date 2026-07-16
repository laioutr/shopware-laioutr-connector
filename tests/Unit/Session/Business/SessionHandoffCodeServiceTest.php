<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\Business;

use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use PHPUnit\Framework\TestCase;

class SessionHandoffCodeServiceTest extends TestCase
{
    public function testGeneratesDistinctHighEntropyHexCodes(): void
    {
        $service = new SessionHandoffCodeService();

        $first = $service->generateCode();
        $second = $service->generateCode();

        static::assertNotSame($first, $second);
        static::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }

    public function testHashCodeIsDeterministicRawSha256(): void
    {
        $service = new SessionHandoffCodeService();

        static::assertSame(hash('sha256', 'a-code', true), $service->hashCode('a-code'));
        static::assertSame(32, \strlen($service->hashCode('a-code')));
    }

    public function testTtlIsSixtySeconds(): void
    {
        static::assertSame(60, SessionHandoffCodeService::TTL_SECONDS);
    }
}
