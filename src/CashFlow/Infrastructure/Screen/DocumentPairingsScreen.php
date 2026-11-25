<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Screen;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\PairDocumentInput;
use Foresi\CashFlow\Application\PairingWrites;
use Foresi\CashFlow\Application\PlannedOccurrences;
use Foresi\CashFlow\Application\UnpairDocumentInput;
use Foresi\CashFlow\Contract\DocumentPairings;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairingChoice;
use Foresi\CashFlow\Contract\PairingOption;
use Foresi\CashFlow\Contract\PairingRefused;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Contract\PairingView;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PairingCandidate;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Shared\Contract\RefusalKind;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cash flow's pairings for the screens of a document module: the panel of a document's page, the dialog that
 * picks an occurrence, and the filter of the documents that wait for a person. Refusals leave as PairingRefused.
 */
final readonly class DocumentPairingsScreen implements DocumentPairings
{
    public function __construct(
        private CashFlowAccess $access,
        private CashFlowStore $store,
        private PairingWrites $writes,
        private PlannedOccurrences $occurrences,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function view(FlowSubject $subject): ?PairingView
    {
        $this->staff();
        $row = $this->store->pairing($subject->type, $subject->id);
        if (!$row instanceof DocumentPairing) {
            return null;
        }
        $current = null;
        if (null !== $row->plannedItemId() && $row->occurrenceOn() instanceof \DateTimeImmutable) {
            $current = $this->option(new PairingCandidate($row->plannedItemId(), $row->occurrenceOn()));
        }
        $candidates = [];
        foreach (PairingState::Proposed === $row->state() ? $row->candidates() : [] as $candidate) {
            $option = $this->option($candidate);
            if ($option instanceof PairingOption) {
                $candidates[] = $option;
            }
        }

        return new PairingView($row->state(), $current, $candidates);
    }

    public function choices(FlowDirection $direction, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $this->staff();
        $choices = [];
        foreach ($this->store->plannedItems() as $item) {
            if ($item->direction() !== $direction) {
                continue;
            }
            $days = $this->occurrences->between($item, $from, $to);
            if ([] !== $days) {
                $choices[] = new PairingChoice($item->id(), $item->name(), $days);
            }
        }
        usort($choices, static fn (PairingChoice $a, PairingChoice $b): int => [$a->name, $a->plannedItemId] <=> [$b->name, $b->plannedItemId]);

        return $choices;
    }

    public function pair(FlowSubject $subject, string $plannedItemId, \DateTimeImmutable $occurrenceOn): void
    {
        $this->refusing(fn (): mixed => $this->writes->pair(new PairDocumentInput($subject->type, $subject->id, $plannedItemId, $occurrenceOn->format('Y-m-d'))));
    }

    public function unpair(FlowSubject $subject): void
    {
        $this->refusing(fn (): mixed => $this->writes->unpair(new UnpairDocumentInput($subject->type, $subject->id)));
    }

    public function toConfirm(string $subjectType): array
    {
        $this->staff();

        return array_values(array_map(
            static fn (DocumentPairing $row): string => $row->subjectId(),
            array_filter($this->store->pairings(PairingState::Proposed), static fn (DocumentPairing $row): bool => $row->subjectType() === $subjectType),
        ));
    }

    private function option(PairingCandidate $candidate): ?PairingOption
    {
        $item = $this->store->plannedItem($candidate->plannedItemId);
        if (!$item instanceof PlannedItem) {
            return null;
        }

        return new PairingOption($item->id(), $item->name(), $candidate->occurrenceOn, $this->urls->generate(PlannedItemScreen::itemRoute($item->direction()), ['id' => $item->id()]));
    }

    private function staff(): void
    {
        $this->refusing($this->access->denyUnlessStaff(...));
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function refusing(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (CashFlowRefused $refused) {
            throw new PairingRefused($refused->reason(), $refused->getMessage(), RefusalKind::Forbidden === $refused->kind(), $refused);
        }
    }
}
