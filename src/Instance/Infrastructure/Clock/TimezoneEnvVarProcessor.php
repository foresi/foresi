<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Infrastructure\Clock;

use Foresi\Instance\Application\ConfiguredZone;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * `%env(timezone:APP_TIMEZONE)%`: the variable as it is when it names a
 * canonical zone of the tz database or is empty, else a failure that names the
 * variable and the value, so a typo stops the instance instead of shifting its
 * dates.
 */
final readonly class TimezoneEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $value = $getEnv($name);
        $value = \is_string($value) ? trim($value) : throw new RuntimeException(\sprintf('The environment variable %s must be a string.', $name));
        if ('' === $value) {
            return '';
        }
        try {
            ConfiguredZone::parse($value);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException(\sprintf('The environment variable %s: %s', $name, $e->getMessage()), $e->getCode(), previous: $e);
        }

        return $value;
    }

    public static function getProvidedTypes(): array
    {
        return ['timezone' => 'string'];
    }
}
