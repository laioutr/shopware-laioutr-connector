<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Integration;

class SessionHandoff
{
    public function __construct(
        public readonly string $contextToken,
        public readonly string $salesChannelId,
        public readonly ?string $loginSuccessCallback,
        public readonly ?string $logoutSuccessCallback,
        public readonly ?string $redirectRoute,
    ) {
    }
}
