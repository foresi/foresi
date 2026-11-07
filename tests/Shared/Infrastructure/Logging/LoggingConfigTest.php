<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\OperatorMailHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The production log: rotating JSON files kept for 30 days, and error mails to
 * the operator at most once an hour per distinct message, from every channel
 * but deprecations, so worker and console failures reach them too.
 */
final class LoggingConfigTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $handlers;

    protected function setUp(): void
    {
        /** @var array{'when@prod': array{monolog: array{handlers: array<string, array<string, mixed>>}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/monolog.yaml');
        $this->handlers = $config['when@prod']['monolog']['handlers'];
    }

    public function testErrorsAreMailedOncePerMessageAnHour(): void
    {
        $filter = $this->handlers['operator_alert'];
        self::assertSame('filter', $filter['type']);
        self::assertSame('error', $filter['min_level']);
        self::assertSame(['!deprecation'], $filter['channels']);

        self::assertIsString($filter['handler']);
        $dedup = $this->handlers[$filter['handler']];
        self::assertSame('deduplication', $dedup['type']);
        self::assertSame(3600, $dedup['time']);
        self::assertSame('error', $dedup['deduplication_level']);
        self::assertTrue($dedup['process_psr_3_messages']);
        self::assertIsString($dedup['handler']);
        self::assertSame(['type' => 'service', 'id' => OperatorMailHandler::class], $this->handlers[$dedup['handler']]);
    }

    public function testTheLogRotatesDailyAndKeeps30Days(): void
    {
        foreach (['file', 'noisy', 'deprecation'] as $name) {
            self::assertSame('rotating_file', $this->handlers[$name]['type'], $name);
            self::assertSame(30, $this->handlers[$name]['max_files'], $name);
            self::assertSame('monolog.formatter.json', $this->handlers[$name]['formatter'], $name);
        }
        self::assertSame('%kernel.logs_dir%/prod.log', $this->handlers['file']['path']);
        self::assertSame('%kernel.logs_dir%/prod.log', $this->handlers['noisy']['path']);
        self::assertSame('warning', $this->handlers['noisy']['level']);
        self::assertSame(['doctrine', 'messenger'], $this->handlers['noisy']['channels']);
        self::assertSame('fingers_crossed', $this->handlers['main']['type']);
        self::assertSame('error', $this->handlers['main']['action_level']);
        self::assertSame('info', $this->handlers['main']['passthru_level']);
    }
}
