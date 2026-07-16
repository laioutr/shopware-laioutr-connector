<?php

declare(strict_types=1);

namespace Laioutr\Connector\Session\Business;

class SessionHandoffCodeService
{
    public const TTL_SECONDS = 60;

    public function generateCode(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashCode(string $code): string
    {
        return hash('sha256', $code, true);
    }
}
