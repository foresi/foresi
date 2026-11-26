<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Lock;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * `%env(lock_dsn:LOCK_DSN)%`: the lock store of the instance. Empty or a bare
 * `flock` locks with files in the instance's share directory, which outlives a
 * release, instead of the system temp directory: instances on one host under one
 * Unix user would share lock names there, and the schedule of all but one of them
 * would never tick. Any other value, such as `flock:///path` or a shared store for
 * several hosts, stays as it is.
 */
final readonly class LockDsnEnvVarProcessor implements EnvVarProcessorInterface
{
    public function __construct(
        #[Autowire(param: 'kernel.share_dir')]
        private string $shareDir,
    ) {
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $value = $getEnv($name);
        $value = \is_string($value) ? trim($value) : throw new RuntimeException(\sprintf('The environment variable %s must be a string.', $name));

        return '' === $value || 'flock' === $value ? 'flock://'.$this->shareDir.'/lock' : $value;
    }

    public static function getProvidedTypes(): array
    {
        return ['lock_dsn' => 'string'];
    }
}
