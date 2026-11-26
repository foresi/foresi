<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

use Foresi\Inbox\Application\InboxTransaction;

/**
 * The transaction of the container, with something done before one of its runs (by its number,
 * from 1): a hook, such as another person's decision in between, or a failure to throw instead.
 */
final class ScriptedTransaction implements InboxTransaction
{
    private int $runs = 0;

    /** @var array<int, \Closure> */
    private array $before = [];

    /** @var array<int, \Throwable> */
    private array $failures = [];

    public function __construct(private readonly InboxTransaction $inner)
    {
    }

    public function before(int $run, \Closure $hook): self
    {
        $this->before[$run] = $hook;

        return $this;
    }

    public function failing(int $run, \Throwable $failure): self
    {
        $this->failures[$run] = $failure;

        return $this;
    }

    public function run(callable $work): mixed
    {
        ++$this->runs;
        if (isset($this->failures[$this->runs])) {
            throw $this->failures[$this->runs];
        }
        if (isset($this->before[$this->runs])) {
            ($this->before[$this->runs])();
        }

        return $this->inner->run($work);
    }
}
