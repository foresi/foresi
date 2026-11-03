<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Support;

use App\Tenancy\Application\Schedule\ScheduledJob;
use App\Tenancy\Application\TenantContext;
use App\Tests\Tenancy\Fixtures\TestNote;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A daily job that remembers each run: the tenant it ran in, the notes it could
 * see and the last period it was given. It can be told to fail. The record is
 * static, as the job is a service of the container and the test reads it.
 */
final class CountingJob implements ScheduledJob
{
    public const string NAME = 'counting';

    /** @var list<array{tenant: string, lastPeriod: ?string, notes: list<string>}> */
    private static array $runs = [];

    private static int $failures = 0;

    public function __construct(private readonly TenantContext $context, private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function period(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d');
    }

    public function run(?string $lastPeriod): void
    {
        if (self::$failures > 0) {
            --self::$failures;

            throw new \RuntimeException('Planned failure.');
        }

        $notes = array_map(static fn (TestNote $note): string => $note->text(), $this->em->getRepository(TestNote::class)->findAll());
        self::$runs[] = ['tenant' => $this->context->current()->toString(), 'lastPeriod' => $lastPeriod, 'notes' => $notes];
    }

    public static function reset(): void
    {
        self::$runs = [];
        self::$failures = 0;
    }

    public static function failNext(int $times): void
    {
        self::$failures = $times;
    }

    /**
     * @return list<array{tenant: string, lastPeriod: ?string, notes: list<string>}>
     */
    public static function runs(): array
    {
        return self::$runs;
    }
}
