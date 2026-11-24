<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Sales\Application\View\DealLists;
use Foresi\Sales\Application\View\DealQueries;
use Foresi\Sales\Domain\Deal;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Infrastructure\View\DqlConditions;

final readonly class DoctrineDealQueries implements DealQueries
{
    private const array COLUMNS = [
        DealLists::STATUS => 'd.status',
        DealLists::OWNER => 'd.ownerUserId',
        DealLists::CLIENT => 'd.clientPartyId',
        DealLists::STAGE => 'd.stageId',
    ];

    public function __construct(private EntityManagerInterface $entityManager, private DealLists $lists, private InstanceClock $clock)
    {
    }

    public function index(ViewFilter $filter, bool $descending = false, string $text = '', array $clientIds = []): array
    {
        $derived = static fn (Condition $condition): array => [match ($condition->field) {
            DealLists::TRASHED => DqlConditions::derivedBoolean($condition, 'd.trashedAt IS NOT NULL'),
            default => throw new \LogicException(\sprintf('The field "%s" of the deals has no SQL.', $condition->field)),
        }, []];
        [$condition, $parameters] = new DqlConditions($this->lists->list(), self::COLUMNS, $derived(...))->compile($filter, $this->clock->today(...), 'v');
        // The deals in the trash only when the view asks for them.
        $trashed = [] === $filter->on(DealLists::TRASHED) ? 'd.trashedAt IS NULL AND ' : '';
        $direction = $descending ? 'DESC' : 'ASC';
        $found = '';
        if ('' !== $text) {
            $found = " AND (LOWER(d.title) LIKE :text ESCAPE '!'".([] === $clientIds ? '' : ' OR d.clientPartyId IN (:clients)').')';
        }
        $query = $this->entityManager->createQuery('SELECT d FROM '.Deal::class.' d WHERE '.$trashed.$condition.$found.' ORDER BY d.title '.$direction.', d.id '.$direction);
        if ('' !== $text) {
            $query->setParameter('text', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%');
            if ([] !== $clientIds) {
                $query->setParameter('clients', $clientIds);
            }
        }
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }
        /** @var list<Deal> $deals */
        $deals = $query->getResult();

        return $deals;
    }
}
