<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Support;

use App\Tenancy\Application\Schedule\OperatorTask;
use App\Tenancy\Application\TenantContext;

/**
 * An operator task that remembers whether a tenant was active in each run. It
 * can be told to fail, and to run a callback while it runs, so a test can try
 * a second run at the same moment. The record is static, as the task is a
 * service of the container and the test reads it.
 */
final class CountingOperatorTask implements OperatorTask
{
    public const string NAME = 'counting-operator';

    /** @var list<bool> whether a tenant was active, per run */
    private static array $runs = [];

    private static int $failures = 0;

    /** @var (\Closure(): void)|null */
    private static ?\Closure $during = null;

    public function __construct(private readonly TenantContext $context)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function every(): string
    {
        return '10 minutes';
    }

    public function run(): void
    {
        self::$runs[] = $this->context->has();
        if (self::$during instanceof \Closure) {
            $during = self::$during;
            self::$during = null;
            $during();
        }
        if (self::$failures > 0) {
            --self::$failures;

            throw new \RuntimeException('Planned failure.');
        }
    }

    /**
     * @return list<bool>
     */
    public static function runs(): array
    {
        return self::$runs;
    }

    public static function failNext(int $times): void
    {
        self::$failures = $times;
    }

    /**
     * @param \Closure(): void $callback
     */
    public static function during(\Closure $callback): void
    {
        self::$during = $callback;
    }

    public static function reset(): void
    {
        self::$runs = [];
        self::$failures = 0;
        self::$during = null;
    }
}
