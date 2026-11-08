<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Label;
use App\Work\Domain\LabelColour;

/**
 * The labels of the organisation. A name is taken whatever its case, so
 * "Urgent" and "urgent" never split one filter in two. A deleted label leaves
 * every task it was on.
 */
final readonly class ManageLabels
{
    public function __construct(
        private Labels $labels,
        private Tasks $tasks,
        private VisibleWork $work,
        private WorkUnit $unit,
    ) {
    }

    /**
     * @throws WorkRefused
     */
    public function create(string $name, LabelColour $colour): Label
    {
        $this->work->assertManager();
        $label = WorkRefused::unlessNamed(static fn (): Label => new Label($name, $colour));
        $this->assertFree($label->name(), null);

        return $this->unit->run(function () use ($label): Label {
            $this->labels->add($label);

            return $label;
        });
    }

    /**
     * @throws WorkRefused
     */
    public function rename(string $labelId, string $name): void
    {
        $label = $this->label($labelId);
        $this->assertFree(trim($name), $label);
        WorkRefused::unlessNamed(static fn () => $label->rename($name));
        $this->unit->run(static fn (): null => null);
    }

    /**
     * @throws WorkRefused
     */
    public function recolour(string $labelId, LabelColour $colour): void
    {
        $label = $this->label($labelId);
        $this->unit->run(static fn () => $label->recolour($colour));
    }

    /**
     * @throws WorkRefused
     */
    public function delete(string $labelId): void
    {
        $label = $this->label($labelId);
        $this->unit->run(function () use ($label): void {
            foreach ($this->tasks->labelled($label) as $taskLabel) {
                $this->tasks->remove($taskLabel);
            }
            $this->labels->remove($label);
        });
    }

    private function label(string $id): Label
    {
        $this->work->assertManager();

        return $this->labels->label($id) ?? throw new WorkRefused('unknown');
    }

    private function assertFree(string $name, ?Label $self): void
    {
        $other = $this->labels->named($name);
        if ($other instanceof Label && $other->id() !== $self?->id()) {
            throw new WorkRefused('label_taken');
        }
    }
}
