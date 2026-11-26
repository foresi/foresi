<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Id;

/**
 * One step of a suggestion: an inbox action (its operation's name) with the
 * input the agent proposed, by the fields' wire names and without the item and
 * mark_done, which the run adds. The person may edit it (final input) or untick
 * it (skipped) before it runs. Once it ran, it holds the record it made, or the
 * refusal's reason and field when its action refused it. A done step never
 * runs again.
 *
 * Audited under its suggestion.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_suggestion_step')]
#[ORM\UniqueConstraint(name: 'uniq_inbox_suggestion_step_position', columns: ['suggestion_id', 'position'])]
class SuggestionStep implements Audited, AuditRooted
{
    public const int MAX_OPERATION = 100;
    public const int MAX_KIND = ItemOutput::MAX_KIND;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** @var array<mixed>|null the person's edit; null while they changed nothing */
    #[ORM\Column(name: 'final_input', type: 'json', nullable: true)]
    private ?array $finalInput = null;

    #[ORM\Column(type: 'boolean')]
    private bool $selected = true;

    #[ORM\Column(type: 'string', length: 16, enumType: StepState::class)]
    private StepState $state = StepState::Pending;

    #[ORM\Column(name: 'record_kind', type: 'string', length: self::MAX_KIND, nullable: true)]
    private ?string $recordKind = null;

    #[ORM\Column(name: 'record_id', type: 'guid', nullable: true)]
    private ?string $recordId = null;

    /** @var array{reason: string, field: string|null}|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $refusal = null;

    #[ORM\Column(name: 'ran_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ranAt = null;

    /**
     * @param array<mixed> $proposedInput
     */
    public function __construct(
        string $id,
        #[ORM\ManyToOne(targetEntity: Suggestion::class, inversedBy: 'steps')]
        #[ORM\JoinColumn(name: 'suggestion_id', nullable: false, onDelete: 'CASCADE')]
        private Suggestion $suggestion,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(type: 'string', length: self::MAX_OPERATION)]
        private string $operation,
        #[ORM\Column(name: 'proposed_input', type: 'json')]
        private array $proposedInput,
    ) {
        if (!Id::isValid($id)) {
            throw new \InvalidArgumentException('A suggestion step has a UUIDv7 id.');
        }
        if ($position < 1 || $position > Suggestion::MAX_STEPS) {
            throw new \InvalidArgumentException('A step is at position 1 to 10.');
        }
        if (1 !== preg_match('/^[a-z0-9_.-]{1,100}$/', $operation)) {
            throw new \InvalidArgumentException('A step names its action by the operation\'s name.');
        }
        $this->mustBeObject($proposedInput);
        $this->id = $id;
    }

    public function auditRoot(): object
    {
        return $this->suggestion;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function suggestion(): Suggestion
    {
        return $this->suggestion;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * @return array<mixed>
     */
    public function proposedInput(): array
    {
        return $this->proposedInput;
    }

    /**
     * @return array<mixed>|null
     */
    public function finalInput(): ?array
    {
        return $this->finalInput;
    }

    /**
     * The input it runs with: the person's edit, else the proposed one.
     *
     * @return array<mixed>
     */
    public function input(): array
    {
        return $this->finalInput ?? $this->proposedInput;
    }

    public function isSelected(): bool
    {
        return $this->selected;
    }

    public function state(): StepState
    {
        return $this->state;
    }

    /** Whether accepting runs it: ticked, and pending or failed. */
    public function isRunnable(): bool
    {
        return $this->selected && \in_array($this->state, [StepState::Pending, StepState::Failed], true);
    }

    public function recordKind(): ?string
    {
        return $this->recordKind;
    }

    public function recordId(): ?string
    {
        return $this->recordId;
    }

    /**
     * @return array{reason: string, field: string|null}|null
     */
    public function refusal(): ?array
    {
        return $this->refusal;
    }

    public function ranAt(): ?\DateTimeImmutable
    {
        return $this->ranAt;
    }

    /**
     * @param array<mixed> $input
     *
     * @throws \LogicException once it is done or skipped
     */
    public function edit(array $input): void
    {
        $this->mustBeRunnable();
        $this->mustBeObject($input);
        $this->finalInput = $input;
    }

    /**
     * Unticked: it never runs, a later retry included.
     *
     * @throws \LogicException once it is done
     */
    public function skip(): void
    {
        if (StepState::Done === $this->state) {
            throw new \LogicException('A done step cannot be skipped.');
        }
        $this->selected = false;
        $this->state = StepState::Skipped;
        $this->refusal = null;
    }

    /**
     * Its action made the record; stored in the same transaction as the record.
     *
     * @param array<mixed>|null $finalInput the input it ran with, references as stored; null keeps the current one
     *
     * @throws \LogicException when it is not to run
     */
    public function done(string $recordKind, string $recordId, \DateTimeImmutable $now, ?array $finalInput = null): void
    {
        $this->mustBeRunnable();
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $recordKind) || !Id::isValid($recordId)) {
            throw new \InvalidArgumentException('A step names its record by its kind and UUIDv7 id.');
        }
        if (null !== $finalInput) {
            $this->mustBeObject($finalInput);
        }
        $this->finalInput = $finalInput ?? $this->input();
        $this->state = StepState::Done;
        $this->recordKind = $recordKind;
        $this->recordId = $recordId;
        $this->refusal = null;
        $this->ranAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Its action refused it: the reason code and field only, never the message,
     * which could quote the input. It may run again.
     *
     * @throws \LogicException when it is not to run
     */
    public function fail(string $reason, ?string $field, \DateTimeImmutable $now): void
    {
        $this->mustBeRunnable();
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reason)) {
            throw new \InvalidArgumentException('A refusal is stored by its reason code.');
        }
        $this->state = StepState::Failed;
        $this->refusal = ['reason' => $reason, 'field' => $field];
        $this->ranAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    private function mustBeRunnable(): void
    {
        if (!$this->isRunnable()) {
            throw new \LogicException(\sprintf('The step is %s.', $this->state->value));
        }
    }

    /**
     * @param array<mixed> $input
     */
    private function mustBeObject(array $input): void
    {
        if ([] !== $input && array_is_list($input)) {
            throw new \InvalidArgumentException('The input of a step is an object of its fields.');
        }
    }
}
