<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Contract\TemplateLine;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\RebillLink;
use Foresi\Party\Application\TransactionalWork;
use Symfony\Component\Uid\Uuid;

/**
 * Which planned item a line of a recurring invoice template rebills. The line
 * and the item must exist and the item must be money going out. A customer
 * that bears no share of the item's mapping this month is warned about, not
 * refused: the mapping may still be set. Staff only.
 */
final readonly class RebillLinks
{
    public const string CUSTOMER_NOT_A_RECIPIENT = MarginRefused::CUSTOMER_NOT_A_RECIPIENT;

    public function __construct(
        private MarginAccess $access,
        private RevenueSources $revenue,
        private CostPairings $pairings,
        private MappingVersions $mappings,
        private EntityManagerInterface $entityManager,
        private InstanceClock $clock,
        private TransactionalWork $transaction,
    ) {
    }

    /**
     * Links the line to the item, or removes its link with null. Setting the
     * item it already has keeps the link as it is; another item starts the
     * link over from today.
     *
     * @throws MarginRefused forbidden, unknown_template_line, unknown_planned_item or not_an_expense
     */
    public function set(string $recurringLineId, ?string $plannedItemId): ?RebillLinkView
    {
        $this->access->denyUnlessStaff();
        $stored = $this->entityManager->getRepository(RebillLink::class)->findOneBy(['recurringLineId' => $recurringLineId]);

        if (null === $plannedItemId) {
            if ($stored instanceof RebillLink) {
                $this->transaction->run(function () use ($stored): void {
                    $this->entityManager->remove($stored);
                    $this->entityManager->flush();
                });
            }

            return null;
        }

        // A link is removed even when its line is gone; one is only made for a line that exists.
        $line = $this->revenue->templateLine($recurringLineId) ?? throw MarginRefused::notFound(MarginRefused::UNKNOWN_TEMPLATE_LINE, 'There is no recurring invoice line with this id, or Invoicing is not installed.');
        $item = $this->pairings->item($plannedItemId) ?? throw AllocationReads::unknownPlannedItem();
        if (!$item->expense) {
            throw MarginRefused::invalid(MarginRefused::NOT_AN_EXPENSE, 'plannedItemId', 'Only a planned cost, money going out, is rebilled.');
        }
        $link = $this->transaction->run(function () use ($stored, $line, $item): RebillLink {
            if ($stored instanceof RebillLink) {
                if ($stored->plannedItemId() !== $item->id) {
                    $stored->pointAt($item->id, $this->clock->today());
                }
                $link = $stored;
            } else {
                $link = new RebillLink(Uuid::v7()->toRfc4122(), $line->id, $item->id, $this->clock->today());
                $this->entityManager->persist($link);
            }
            $this->entityManager->flush();

            return $link;
        });

        return $this->view($link, $item, $line);
    }

    /**
     * @throws MarginRefused forbidden
     */
    public function ofLine(string $recurringLineId): ?RebillLinkView
    {
        return $this->ofLines([$recurringLineId])[$recurringLineId] ?? null;
    }

    /**
     * @param list<string> $recurringLineIds
     *
     * @return array<string, RebillLinkView> by the id of the line, only the lines that have a link
     *
     * @throws MarginRefused forbidden
     */
    public function ofLines(array $recurringLineIds): array
    {
        $this->access->denyUnlessStaff();
        /** @var list<RebillLink> $links */
        $links = $this->entityManager->getRepository(RebillLink::class)->findBy(['recurringLineId' => $recurringLineIds]);
        $views = [];
        foreach ($links as $link) {
            $views[$link->recurringLineId()] = $this->view($link, $this->pairings->item($link->plannedItemId()), $this->revenue->templateLine($link->recurringLineId()));
        }

        return $views;
    }

    private function view(RebillLink $link, ?PlannedCostItem $item, ?TemplateLine $line): RebillLinkView
    {
        $warnings = [];
        if ($line instanceof TemplateLine && !\in_array(strtolower($line->customerId), $this->mappings->plannedClients($link->plannedItemId(), $this->clock->today()), true)) {
            $warnings[] = self::CUSTOMER_NOT_A_RECIPIENT;
        }

        return new RebillLinkView($link->recurringLineId(), $link->plannedItemId(), $item->name ?? $link->plannedItemId(), $item?->url, $link->createdOn()->format('Y-m-d'), $warnings);
    }
}
