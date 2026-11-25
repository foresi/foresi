<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Screen;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Application\ActivityTypeNames;
use Foresi\Activities\Application\RecordLink;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Activities\Infrastructure\Controller\ActivityController;
use Foresi\Attachment\Application\VisibleAttachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Attachment\Infrastructure\Controller\AttachmentController;
use Foresi\Identity\Application\UserRepository;
use Foresi\Instance\Application\InstanceClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the screens of Activities show: an activity as an item of a timeline,
 * its time as the instance's locale writes it in the instance's zone, the
 * types to choose from and the refusals in the person's language.
 */
final readonly class ActivityScreen
{
    /** The icon of each timing, from Lucide. */
    private const array ICONS = ['timed' => 'calendar', 'date' => 'square-check-big', 'none' => 'text-quote'];

    /** The seeded e-mail type, whose activity is an e-mail recorded with its sender as the first line of its body. */
    private const string EMAIL = 'email';

    /** How the body of an e-mail activity opens: "From: Client A <a@example.com>". */
    private const string SENDER_PREFIX = 'From: ';

    public function __construct(
        private ActivitiesStore $store,
        private ActivityReads $reads,
        private ActivityTargets $targets,
        private ActivityTypeNames $names,
        private VisibleAttachments $attachments,
        private UserRepository $users,
        private InstanceClock $clock,
        private LocaleSwitcher $locales,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * An activity as the timeline and its record show it.
     *
     * @param string|null $exceptKind a record the timeline belongs to, left out of its links
     *
     * @return array<string, mixed>
     */
    public function item(Activity $activity, ?string $exceptKind = null, ?string $exceptId = null): array
    {
        $type = $this->typeOf($activity);
        $today = $this->clock->today();
        $due = $activity->plannedAt() ?? $activity->plannedOn();

        return [
            'id' => $activity->id(),
            'url' => $this->urls->generate(ActivityController::SHOW_ROUTE, ['id' => $activity->id()]),
            'type' => $this->names->of($type),
            'icon' => self::EMAIL === $type->key() ? 'mail' : self::ICONS[$type->timing()->value],
            'sender' => self::EMAIL === $type->key() ? $this->sender($activity->body()) : null,
            'subject' => $activity->subject(),
            'body' => $activity->body(),
            'done' => $activity->isDone(),
            'trashed' => $activity->isTrashed(),
            'overdue' => !$activity->isDone() && $due instanceof \DateTimeImmutable && ($activity->plannedAt() instanceof \DateTimeImmutable ? $this->local($due) : $due)->format('Y-m-d') < $today->format('Y-m-d'),
            'when' => $this->when($activity),
            'datetime' => ($activity->doneAt() ?? $due)?->format(\DATE_ATOM),
            'duration' => null === $activity->durationMinutes() ? null : $this->translator->trans('activities.duration', ['minutes' => $activity->durationMinutes()]),
            'outcome' => $activity->outcome(),
            'owner' => $this->users->namesOf([$activity->ownerUserId()])[$activity->ownerUserId()] ?? null,
            'links' => array_values(array_filter(array_map(
                fn (RecordLink $link): ?array => $link->kind === $exceptKind && $link->id === $exceptId ? null : (array) $this->targets->describe($link),
                $this->reads->links($activity),
            ))),
            'files' => array_map(fn (Attachment $file): array => [
                'id' => $file->id(),
                'name' => $file->fileName(),
                'url' => $this->urls->generate(AttachmentController::ROUTE, ['id' => $file->id()]),
            ], $this->attachments->of(Activity::ATTACHMENT_KIND, $activity->id())),
        ];
    }

    /**
     * The sender line an e-mail activity's body opens with, or null when it opens with something else.
     */
    private function sender(string $body): ?string
    {
        $line = trim(explode("\n", $body, 2)[0]);

        return str_starts_with($line, self::SENDER_PREFIX) && '' !== trim(substr($line, \strlen(self::SENDER_PREFIX))) ? $line : null;
    }

    /**
     * When it happened or is planned: "Done 22 Nov 2026, 10:00", "25 Nov 2026, 10:00" or "27 Nov 2026".
     */
    public function when(Activity $activity): string
    {
        if ($activity->doneAt() instanceof \DateTimeImmutable) {
            return $this->translator->trans('activities.when.done', ['time' => $this->moment($activity->doneAt())]);
        }
        if ($activity->plannedAt() instanceof \DateTimeImmutable) {
            return $this->moment($activity->plannedAt());
        }

        // A day is the day it is, in no zone.
        return $activity->plannedOn() instanceof \DateTimeImmutable ? $this->format($activity->plannedOn(), 'dMMMy', false) : '';
    }

    public function typeOf(Activity $activity): ActivityType
    {
        return $this->store->type($activity->typeId()) ?? throw new \LogicException('An activity has its type.');
    }

    public function typeName(ActivityType $type): string
    {
        return $this->names->of($type);
    }

    /**
     * The active types to choose from, and the type of the activity changed even when archived.
     *
     * @return array<string, string> name by id
     */
    public function typeOptions(?string $current = null): array
    {
        $options = [];
        foreach ($this->store->types(true) as $type) {
            if (!$type->isArchived() || $type->id() === $current) {
                $options[$type->id()] = $this->names->of($type);
            }
        }

        return $options;
    }

    /**
     * @return array<string, string> the timing of each type, by id, for the form to show its fields
     */
    public function timings(): array
    {
        $timings = [];
        foreach ($this->store->types(true) as $type) {
            $timings[$type->id()] = $type->timing()->value;
        }

        return $timings;
    }

    /**
     * The first active type of a timing, for a dialog that opens on it.
     */
    public function firstOf(ActivityTiming ...$timings): ?ActivityType
    {
        foreach ($this->store->types(false) as $type) {
            if (\in_array($type->timing(), $timings, true)) {
                return $type;
            }
        }

        return null;
    }

    /** A time as a datetime-local field shows it, in the instance's zone. */
    public function field(?\DateTimeImmutable $time): string
    {
        return $time instanceof \DateTimeImmutable ? $this->local($time)->format('Y-m-d\TH:i') : '';
    }

    /**
     * A refusal in the person's language when there is one, else its own message.
     */
    public function refusal(ActivitiesRefused $refused): string
    {
        $key = 'activities.error.'.$refused->reason();
        foreach ([$key.'.'.$refused->field(), $key] as $candidate) {
            $text = $this->translator->trans($candidate);
            if ($text !== $candidate) {
                return $text;
            }
        }

        return $refused->getMessage();
    }

    private function moment(\DateTimeImmutable $time): string
    {
        return $this->format($time, 'dMMMyHHmm');
    }

    private function format(\DateTimeImmutable $time, string $skeleton, bool $inZone = true): string
    {
        $locale = $this->locales->getLocale();
        $local = $inZone ? $this->local($time) : $time;
        $pattern = new \IntlDatePatternGenerator($locale)->getBestPattern($skeleton);
        $text = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $local->getTimezone(), null, false === $pattern ? 'd MMM y' : $pattern)->format($local);

        return false === $text ? $local->format('Y-m-d H:i') : $text;
    }

    private function local(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone($this->clock->timezone());
    }
}
