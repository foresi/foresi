<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Meetings\Application\MeetingPeople;
use Foresi\Meetings\Application\MeetingQueries;
use Foresi\Meetings\Application\MeetingsClock;
use Foresi\Meetings\Application\MeetingSearch;
use Foresi\Meetings\Application\MeetingVisibility;
use Foresi\Meetings\Application\View\MeetingLists;
use Foresi\Meetings\Contract\Field\MeetingExtension;
use Foresi\Meetings\Domain\Attendee;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingLink;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Infrastructure\View\DqlConditions;

/**
 * The meetings of a search in DQL, among those MeetingVisibility lets the
 * person see. The series and the day are columns; "mine" (the person attends)
 * and "when" (upcoming from today, past before it, in the instance's zone) are
 * derived.
 */
final readonly class DoctrineMeetingQueries implements MeetingQueries
{
    private const array COLUMNS = [MeetingLists::SERIES => 's.id', MeetingLists::HELD_ON => 'm.heldOn'];

    public function __construct(
        private EntityManagerInterface $em,
        private MeetingLists $lists,
        private MeetingVisibility $visibility,
        private MeetingPeople $people,
        private MeetingsClock $clock,
        private Fields $fields,
    ) {
    }

    public function search(MeetingSearch $search): array
    {
        $today = $this->clock->today();
        $me = $this->people->me() ?? '';
        $derived = (static fn (Condition $condition, string $suffix): array => match ($condition->field) {
            MeetingLists::MINE => [DqlConditions::derivedBoolean($condition, 'EXISTS (SELECT 1 FROM '.Attendee::class.' mine'.$suffix.' WHERE mine'.$suffix.'.meeting = m AND mine'.$suffix.'.membershipId = :mine'.$suffix.')'), ['mine'.$suffix => [$me, null]]],
            MeetingLists::WHEN => [DqlConditions::derivedEnum($condition, [MeetingLists::UPCOMING => 'm.heldOn >= :today'.$suffix, MeetingLists::PAST => 'm.heldOn < :today'.$suffix]), ['today'.$suffix => [$today, 'date_immutable']]],
            default => throw new \LogicException(\sprintf('The field "%s" of the meetings has no SQL.', $condition->field)),
        });
        [$condition, $parameters] = new DqlConditions($this->lists->list(), self::COLUMNS, $derived(...))->compile($search->filter, static fn (): \DateTimeImmutable => $today, 'v');
        [$visible, $visibleParameters] = $this->visibility->condition('m', 's', 'va', 'vm');
        $where = [$visible, $condition, $search->trash ? 'm.deletedAt IS NOT NULL' : 'm.deletedAt IS NULL'];
        $named = $visibleParameters;
        if ('' !== $search->text) {
            $where[] = "(LOWER(m.title) LIKE :text ESCAPE '!' OR LOWER(m.minutes) LIKE :text ESCAPE '!'".implode('', array_map(static fn (string $field): string => ' OR '.$field, $this->fields->searched(MeetingExtension::RECORD, 'm', 'text'))).')';
            $named['text'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(mb_substr($search->text, 0, MeetingSearch::MAX_TEXT))).'%';
        }
        if (null !== $search->seriesId) {
            $where[] = 's.id = :series';
            $named['series'] = $search->seriesId;
        }
        if (null !== $search->attendeeId) {
            $where[] = 'EXISTS (SELECT 1 FROM '.Attendee::class.' sa WHERE sa.meeting = m AND sa.membershipId = :attendee)';
            $named['attendee'] = $search->attendeeId;
        }
        if (null !== $search->linkKind && null !== $search->linkId) {
            $where[] = 'EXISTS (SELECT 1 FROM '.MeetingLink::class.' sl WHERE sl.meeting = m AND sl.kind = :link_kind AND sl.targetId = :link_id)';
            $named['link_kind'] = $search->linkKind;
            $named['link_id'] = $search->linkId;
        }
        $query = $this->em->createQuery('SELECT m FROM '.Meeting::class.' m LEFT JOIN m.series s WHERE '.implode(' AND ', array_map(static fn (string $part): string => '('.$part.')', $where))
            .($search->from instanceof \DateTimeImmutable ? ' AND m.heldOn >= :from' : '')
            .($search->to instanceof \DateTimeImmutable ? ' AND m.heldOn <= :to' : '')
            .($search->oldestFirst ? ' ORDER BY m.heldOn ASC, m.startsAt ASC, m.id ASC' : ' ORDER BY m.heldOn DESC, m.startsAt DESC, m.id DESC'));
        foreach ($named as $name => $value) {
            $query->setParameter($name, $value);
        }
        if ($search->from instanceof \DateTimeImmutable) {
            $query->setParameter('from', $search->from, 'date_immutable');
        }
        if ($search->to instanceof \DateTimeImmutable) {
            $query->setParameter('to', $search->to, 'date_immutable');
        }
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }
        /** @var list<Meeting> $meetings */
        $meetings = $query->getResult();
        if (null !== $search->afterId) {
            $ids = array_map(static fn (Meeting $meeting): string => $meeting->id(), $meetings);
            $at = array_search($search->afterId, $ids, true);
            $meetings = false === $at ? [] : \array_slice($meetings, $at + 1);
        }
        $meetings = \array_slice($meetings, 0, $search->limit);
        // The custom fields of the rows, read once for their columns (ADR 0045).
        $this->fields->values(MeetingExtension::RECORD, array_map(static fn (Meeting $meeting): string => $meeting->id(), $meetings));

        return $meetings;
    }

    public function todayCount(): int
    {
        [$visible, $parameters] = $this->visibility->condition('m', 's', 'va', 'vm');
        $query = $this->em->createQuery('SELECT COUNT(m.id) FROM '.Meeting::class.' m LEFT JOIN m.series s WHERE ('.$visible.') AND m.deletedAt IS NULL AND m.heldOn = :today')
            ->setParameter('today', $this->clock->today(), 'date_immutable');
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return (int) $query->getSingleScalarResult();
    }
}
