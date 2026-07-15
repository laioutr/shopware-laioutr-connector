<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration;

use Laioutr\Connector\Session\Integration\CallbackRedirector;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\Routing\RouterInterface;

class PluginWiringTest extends TestCase
{
    use KernelTestBehaviour;

    public function testServicesAndRoutesAreAvailable(): void
    {
        static::assertInstanceOf(
            CallbackRedirector::class,
            static::getContainer()->get(CallbackRedirector::class),
        );

        $routeCollection = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        static::assertSame(
            '/laioutr/connect-session',
            $routeCollection->get('frontend.laioutr.connect-session')?->getPath(),
        );
        static::assertSame(
            '/laioutr/cookie-bridge',
            $routeCollection->get('frontend.laioutr.cookie-bridge')?->getPath(),
        );
    }
}
