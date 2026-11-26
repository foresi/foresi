<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The projects an hourly line may link to, from the source installed
 * (LineProjectSource); with none, there are no projects, and a line given
 * one is refused as for an unknown project. The time logged on them comes
 * from LineProjectTime; with none, no time is known. A deal page and the deal's JSON
 * ask for each linked line, so a project and its logged time are read once
 * per request (reset between requests and messages).
 */
final class LineProjects implements ResetInterface
{
    private readonly ?LineProjectSource $source;

    private readonly ?LineProjectTime $time;

    /** @var array<string, LineProject|null> */
    private array $found = [];

    /** @var array<string, int|null> */
    private array $minutes = [];

    /**
     * @param iterable<LineProjectSource> $sources
     * @param iterable<LineProjectTime>   $times
     */
    public function __construct(
        #[AutowireIterator(LineProjectSource::TAG)] iterable $sources = [],
        #[AutowireIterator(LineProjectTime::TAG)] iterable $times = [],
    ) {
        $this->source = self::first($sources);
        $this->time = self::first($times);
    }

    /**
     * @template T of object
     *
     * @param iterable<T> $all
     *
     * @return T|null
     */
    private static function first(iterable $all): ?object
    {
        foreach ($all as $one) {
            return $one;
        }

        return null;
    }

    /** Whether projects can be linked at all: Work is installed. */
    public function installed(): bool
    {
        return $this->source instanceof LineProjectSource;
    }

    public function find(string $projectId): ?LineProject
    {
        if (!\array_key_exists($projectId, $this->found)) {
            $this->found[$projectId] = $this->source?->find($projectId);
        }

        return $this->found[$projectId];
    }

    /**
     * @return list<LineProject> the active projects of the client and those of no client, by name
     */
    public function ofClient(string $clientId): array
    {
        return $this->source?->ofClient($clientId) ?? [];
    }

    /**
     * The billable minutes logged on a project the person sees; null for one
     * they do not see, whose time is not theirs to know, and without time tracked.
     */
    public function billableMinutes(string $projectId): ?int
    {
        if (!\array_key_exists($projectId, $this->minutes)) {
            $this->minutes[$projectId] = $this->time instanceof LineProjectTime && $this->find($projectId) instanceof LineProject ? $this->time->billableMinutes($projectId) : null;
        }

        return $this->minutes[$projectId];
    }

    public function reset(): void
    {
        $this->found = [];
        $this->minutes = [];
    }
}
