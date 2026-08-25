<?php

declare(strict_types=1);

namespace Laioutr\Connector\Embedded;

/**
 * Config keys for the two independent embedded-storefront settings.
 *
 * They are separate because they answer separate questions: embedded mode is how the storefront
 * is presented (framed, chrome hidden, bridge loaded), lockdown is who owns the content pages.
 * A project that sends shoppers to the storefront's own checkout on its own domain wants lockdown
 * without embedding.
 */
final class EmbeddedConfig
{
    public const EMBEDDED_MODE = 'LaioutrConnector.config.embeddedModeEnabled';
    public const LOCKDOWN = 'LaioutrConnector.config.lockdownEnabled';

    public const FINISH_FALLBACK_URL = 'LaioutrConnector.config.finishFallbackUrl';
    public const CHECKOUT_FALLBACK_URL = 'LaioutrConnector.config.checkoutFallbackUrl';
}
