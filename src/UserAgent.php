<?php

declare(strict_types=1);

namespace LessorMQ;

use Composer\InstalledVersions;

/**
 * The User-Agent a client sends: the library and the version Composer installed, so the
 * admin's list of workers shows which release each one runs.
 */
final class UserAgent
{
    /**
     * This library: lessormq-php/0.4.10.
     */
    public static function default(): string
    {
        return self::product('lessormq-php', 'lessormq/client');
    }

    /**
     * $name and the version of the Composer package $package: 0.4.10 for a release,
     * dev-main for a checkout, unknown when Composer cannot say.
     */
    public static function product(string $name, string $package): string
    {
        return $name . '/' . self::version($package);
    }

    private static function version(string $package): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
            return 'unknown';
        }
        $version = InstalledVersions::getPrettyVersion($package);
        if ($version === null || $version === '') {
            return 'unknown';
        }

        // The tags of the client repositories are v0.4.10.
        return preg_replace('/^v(?=\d)/', '', $version) ?? $version;
    }
}
