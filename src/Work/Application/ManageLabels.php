<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\LabelColour;
use Psr\Clock\ClockInterface;

/**
 * The labels of the organisation. A name is taken whatever its case, so
 * "Urgent" and "urgent" never split one filter in two, but only by a label out
 * of the trash.
 *
 * Deleting a label puts it in the trash: its tasks keep it, hidden on each,
 * so restoring puts it back on the same tasks. That is stricter than Linear
 * and GitHub, which take a deleted label off every issue for good. A restore
 * is refused while another label has taken the name.
 */
final readonly class ManageLabels
{
    public function __construct(
        private Labels $labels,
        private VisibleWork $work,
        private WorkUnit $unit,
        private AuditActor $actor,
        private ClockInterface $clock,
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
    public function delete(string $labelId): Label
    {
        $label = $this->label($labelId);
        $userId = $this->actor->userId() ?? throw new WorkRefused('not_allowed');

        return $this->unit->run(function () use ($label, $userId): Label {
            $label->trash($userId, \DateTimeImmutable::createFromInterface($this->clock->now()));

            return $label;
        });
    }

    /**
     * Takes the label out of the trash, back on the tasks it was on.
     *
     * @throws WorkRefused
     */
    public function restore(string $labelId): Label
    {
        $this->work->assertManager();
        $label = $this->labels->trashed($labelId) ?? throw new WorkRefused('unknown');
        if ($this->labels->named($label->name()) instanceof Label) {
            throw new WorkRefused('label_restore_taken');
        }

        return $this->unit->run(static function () use ($label): Label {
            $label->restore();

            return $label;
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
