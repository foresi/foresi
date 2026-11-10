<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Identity\Application\People;
use App\Identity\Application\Person;
use App\Identity\Domain\Role;
use App\Tenancy\Application\TenantClock;
use App\Work\Application\TimeDay;
use App\Work\Application\TimeEntryRow;
use App\Work\Application\TimeQueries;
use App\Work\Application\TimeWeek;
use App\Work\Infrastructure\Twig\TimeFunctions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The week of time of a person, Monday to Sunday, with the entries of each day
 * and what the day and the week add up to (Clockify, Costlocker timesheet).
 * The header moves a week back or on, or to this one, and logs time in the
 * dialog that picks the project and the task. An owner reads the week of
 * anyone in the organisation; everyone else reads their own. Each entry links
 * to its task, or to its project without one, and its author or an owner edits
 * and deletes it here as on the task. Guests have no time: the page is closed
 * to them.
 */
final class TimeWeekController extends AbstractController
{
    public const string ROUTE = 'app_time';

    public function __construct(
        private readonly TimeQueries $time,
        private readonly People $people,
        private readonly OwnerCheck $owner,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly TimeFunctions $format,
        private readonly AuditActor $actor,
    ) {
    }

    #[Route('/time', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $today = $this->clock->today();
        $asked = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('week'));
        $day = $asked instanceof \DateTimeImmutable ? $asked : $today;
        $owner = $this->owner->isOwner();
        $people = $owner ? $this->people() : [];
        $person = $request->query->getString('person');
        $person = isset($people[$person]) ? $person : '';
        $week = $this->time->week($day, '' === $person ? null : $person) ?? throw new NotFoundHttpException();
        $monday = $week->monday;
        $thisMonday = $today->setISODate((int) $today->format('o'), (int) $today->format('W'));
        $query = '' === $person ? [] : ['person' => $person];
        $url = fn (\DateTimeImmutable $monday): string => $this->generateUrl(self::ROUTE, ($monday->format('Y-m-d') === $thisMonday->format('Y-m-d') ? [] : ['week' => $monday->format('Y-m-d')]) + $query);
        $here = $url($monday);
        $mine = '' === $person;
        $inWeek = $today >= $monday && $today < $monday->modify('+7 days');

        return $this->render('work/time/week.html.twig', [
            'range' => $this->range($week),
            'total' => $this->format->duration($week->totalMinutes),
            'days' => array_map(fn (TimeDay $day): array => $this->day($day, $today, $here), $week->days),
            'previous' => $url($monday->modify('-7 days')),
            'next' => $url($monday->modify('+7 days')),
            'current' => $url($thisMonday),
            'isCurrent' => $monday->format('Y-m-d') === $thisMonday->format('Y-m-d'),
            'week' => $monday->format('Y-m-d'),
            'here' => $here,
            'mine' => $mine,
            'logUrl' => $mine ? $this->generateUrl('app_time_new', ['day' => ($inWeek ? $today : $monday)->format('Y-m-d'), 'return' => $here]) : null,
            // An owner alone in the organisation has no one else to switch to.
            'people' => [] === $people ? [] : ['' => $this->translator->trans('work.time.person_me')] + $people,
            'person' => $person,
        ]);
    }

    /**
     * The people whose week an owner reads, but the owner: the staff, by name.
     *
     * @return array<string, string> names by membership id
     */
    private function people(): array
    {
        $userId = $this->actor->userId();
        $me = null === $userId ? null : $this->people->membershipOf($userId)?->id();
        $people = [];
        foreach ($this->people->all() as $person) {
            if (Role::Guest !== $person->role && $person->membershipId !== $me) {
                $people[$person->membershipId] = $this->name($person);
            }
        }
        asort($people, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $people;
    }

    private function name(Person $person): string
    {
        return $person->active ? $person->name : $this->translator->trans('work.time.person_inactive', ['name' => $person->name]);
    }

    /**
     * "2–8 Nov 2026" in the language of the page, as the dates of a range read.
     */
    private function range(TimeWeek $week): string
    {
        $format = $this->translator->trans('format.date');

        return $this->translator->trans('work.time.week_range', ['from' => $week->monday->format($format), 'until' => $week->monday->modify('+6 days')->format($format)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function day(TimeDay $day, \DateTimeImmutable $today, string $here): array
    {
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'cccc');
        $weekday = (string) $formatter->format($day->date->setTime(12, 0));
        $date = $day->date->format($this->translator->trans('format.date'));

        return [
            'key' => $day->date->format('Y-m-d'),
            'weekday' => mb_strtoupper(mb_substr($weekday, 0, 1)).mb_substr($weekday, 1),
            'date' => $date,
            'today' => $day->date->format('Y-m-d') === $today->format('Y-m-d'),
            'total' => $this->format->duration($day->totalMinutes),
            'entries' => array_map(fn (TimeEntryRow $entry): array => [
                'id' => $entry->id,
                'title' => $entry->taskTitle ?? $entry->projectName,
                'href' => null === $entry->taskId ? $this->generateUrl(ProjectController::PROJECT_ROUTE, ['id' => $entry->projectId]) : $this->generateUrl(TaskController::ROUTE, ['id' => $entry->taskId]),
                'project' => null === $entry->taskId ? null : $entry->projectName,
                'duration' => null === $entry->minutes ? null : $this->format->duration($entry->minutes),
                'note' => $entry->note,
                'billable' => $entry->billable,
                'running' => $entry->running,
                'invoiced' => $entry->invoiced,
                'editable' => $entry->editable && !$entry->running,
                'deletable' => $entry->editable,
                'editUrl' => $this->generateUrl('app_time_edit', ['id' => $entry->id, 'return' => $here]),
                'date' => $date,
            ], $day->entries),
        ];
    }
}
