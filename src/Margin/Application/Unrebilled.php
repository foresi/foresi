<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Margin\Contract\TemplateLine;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\RebillLink;
use Foresi\Margin\Domain\RebillPairing;

/**
 * The costs paid and not rebilled. An occurrence O of a planned item I is
 * flagged for a client R when R's recurring invoice has a line that rebills I,
 * R bears a share of I's mapping in the month of O (a client dropped from the
 * mapping is not flagged), a cost document is paired with O (a proposal does
 * not count), no invoice line of R is paired with O, and O is not in a month
 * before the link was made (the month of the link counts). The month of an
 * occurrence is the month of its day. Staff only.
 */
final readonly class Unrebilled
{
    public function __construct(
        private MarginAccess $access,
        private MarginRanges $ranges,
        private RevenueSources $revenue,
        private CostPairings $pairings,
        private MappingVersions $mappings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string|null $fromMonth YYYY-MM; two months before the last one when left out
     * @param string|null $toMonth   YYYY-MM; the current month when left out
     *
     * @return list<UnrebilledOccurrence> by occurrence day, then item, then client
     *
     * @throws MarginRefused forbidden, invalid_month, range_reversed or range_too_long
     */
    public function between(?string $fromMonth = null, ?string $toMonth = null): array
    {
        $this->access->denyUnlessStaff();

        return $this->inRange($this->ranges->range($fromMonth, $toMonth));
    }

    /**
     * @return list<UnrebilledOccurrence>
     */
    public function inRange(MarginRange $range): array
    {
        /** @var list<RebillLink> $links */
        $links = $this->entityManager->getRepository(RebillLink::class)->findBy([], ['recurringLineId' => 'ASC']);
        /** @var array<string, UnrebilledOccurrence> $flagged one per item, day and client */
        $flagged = [];
        foreach ($links as $link) {
            $line = $this->revenue->templateLine($link->recurringLineId());
            $item = $this->pairings->item($link->plannedItemId());
            if (!$line instanceof TemplateLine || !$item instanceof PlannedCostItem) {
                continue;
            }
            $client = strtolower($line->customerId);
            foreach ($this->pairings->occurrencesWithDocumentsFrom($item->id, $range->firstDay()) as $day) {
                $month = $day->format('Y-m');
                // @infection-ignore-all A day and an id are fixed in width, so every way of writing the key tells the three apart.
                $key = $item->id.' '.$day->format('Y-m-d').' '.$client;
                if (!$range->contains($month) || isset($flagged[$key]) || $month < $link->createdOn()->format('Y-m')) {
                    continue;
                }
                if (!\in_array($client, $this->mappings->plannedClients($item->id, $day), true) || $this->rebilled($item->id, $day, $client)) {
                    continue;
                }
                $flagged[$key] = new UnrebilledOccurrence($item->id, $item->name, $item->url, $day, $month, $client, $line->templateId, $line->url, $line->id);
            }
        }
        // usort numbers the list again.
        $found = $flagged;
        usort($found, static fn (UnrebilledOccurrence $one, UnrebilledOccurrence $two): int => [$one->occurrenceOn, $one->itemName, $one->plannedItemId, $one->recipientId] <=> [$two->occurrenceOn, $two->itemName, $two->plannedItemId, $two->recipientId]);

        return $found;
    }

    private function rebilled(string $plannedItemId, \DateTimeImmutable $day, string $client): bool
    {
        return $this->entityManager->getRepository(RebillPairing::class)->findOneBy(['plannedItemId' => $plannedItemId, 'occurrenceOn' => $day, 'customerId' => $client]) instanceof RebillPairing;
    }
}
