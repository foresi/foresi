<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Perf;

/**
 * How many records of each type the performance seed writes. Tiny is for PHPUnit,
 * small for a quick look on a laptop, large for the baseline itself; scaled() is
 * tiny times a factor, for the tests that compare query counts at two sizes.
 */
final readonly class PerfSize
{
    private function __construct(
        public string $name,
        public int $contacts,
        public int $invoices,
        public int $payments,
        public int $receivedDocuments,
        public int $projects,
        public int $tasks,
        public int $timeEntries,
        public int $deals,
        public int $activities,
    ) {
        foreach ($this->counts() as $type => $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException(\sprintf('The count of %s cannot be negative.', $type));
            }
        }
        if ($payments > $invoices) {
            throw new \InvalidArgumentException('Each payment pays one invoice in full, so there cannot be more payments than invoices.');
        }
        // Invoices, deals and projects need someone to bill or sell to, tasks a project, time a task.
        if ($contacts < 1 && $invoices + $receivedDocuments + $deals + $activities > 0 || $projects < 1 && $tasks > 0 || $tasks < 1 && $timeEntries > 0) {
            throw new \InvalidArgumentException('Invoices, received documents, deals and activities need contacts, tasks a project and time entries a task.');
        }
    }

    public static function tiny(): self
    {
        return new self('tiny', 5, 10, 9, 5, 2, 10, 20, 3, 10);
    }

    public static function small(): self
    {
        return new self('small', 200, 500, 450, 300, 20, 200, 2_000, 50, 200);
    }

    public static function large(): self
    {
        return new self('large', 10_000, 20_000, 18_000, 10_000, 500, 20_000, 100_000, 2_000, 20_000);
    }

    /**
     * Every count of tiny times the factor.
     */
    public static function scaled(int $factor): self
    {
        if ($factor < 1) {
            throw new \InvalidArgumentException('The factor is a positive whole number.');
        }
        $tiny = self::tiny();

        return new self(
            \sprintf('tiny-x%d', $factor),
            $tiny->contacts * $factor,
            $tiny->invoices * $factor,
            $tiny->payments * $factor,
            $tiny->receivedDocuments * $factor,
            $tiny->projects * $factor,
            $tiny->tasks * $factor,
            $tiny->timeEntries * $factor,
            $tiny->deals * $factor,
            $tiny->activities * $factor,
        );
    }

    /**
     * tiny, small, large, or tiny-x<factor> as scaled() names it.
     */
    public static function fromName(string $name): self
    {
        return match (true) {
            'tiny' === $name => self::tiny(),
            'small' === $name => self::small(),
            'large' === $name => self::large(),
            1 === preg_match('/^tiny-x([1-9]\d{0,3})$/D', $name, $match) => self::scaled((int) $match[1]),
            default => throw new \InvalidArgumentException(\sprintf('There is no size "%s"; the sizes are tiny, small, large and tiny-x<factor>.', $name)),
        };
    }

    /**
     * @return array<string, int> by record type, in the order the seed writes them
     */
    public function counts(): array
    {
        return [
            'contacts' => $this->contacts,
            'invoices' => $this->invoices,
            'payments' => $this->payments,
            'received_documents' => $this->receivedDocuments,
            'projects' => $this->projects,
            'tasks' => $this->tasks,
            'time_entries' => $this->timeEntries,
            'deals' => $this->deals,
            'activities' => $this->activities,
        ];
    }
}
