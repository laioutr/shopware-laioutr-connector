<?php

declare(strict_types=1);

/*
 * Loads the Composer autoloader that provides the Shopware classes we analyse.
 *
 * The plugin gets analysed from several layouts: inside a Shopware project
 * (custom/plugins/LaioutrConnector, Shopware in vendor/shopware/core), inside a
 * Shopware monorepo checkout (Shopware in src/Core), and standalone with its own
 * vendor directory (shopware-cli extension validate). Instead of hard-coding a
 * relative path for one of them, walk up until a Composer autoloader that knows
 * Shopware shows up.
 */

$directory = __DIR__;

while (true) {
    if (is_file($directory . '/vendor/autoload.php')
        && (is_dir($directory . '/vendor/shopware/core') || is_dir($directory . '/src/Core'))
    ) {
        return require $directory . '/vendor/autoload.php';
    }

    $parent = \dirname($directory);

    if ($parent === $directory) {
        throw new RuntimeException(sprintf(
            'Could not find a Composer autoloader providing Shopware above "%s".',
            __DIR__,
        ));
    }

    $directory = $parent;
}
