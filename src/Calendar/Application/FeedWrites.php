<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Domain\FeedToken;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Instance\Application\InstanceClock;

/**
 * A person creates, renames and revokes their own calendar feeds; the staff only
 * (ADR 0041). Someone else's feed is as unknown as one that does not exist.
 */
final readonly class FeedWrites
{
    public function __construct(
        private CalendarStore $store,
        private StaffCheck $staff,
        private AuditActor $actor,
        private InstanceClock $clock,
    ) {
    }

    /**
     * @throws CalendarRefused
     */
    public function create(CreateFeedInput $input): IssuedFeed
    {
        $userId = $this->me();
        $name = $this->name($input->name);

        return $this->store->transaction(function () use ($userId, $name): IssuedFeed {
            $token = FeedToken::generate();
            $feed = new CalendarFeed($userId, $name, FeedToken::hash($token), $this->now());
            $this->store->add($feed);

            return new IssuedFeed($feed, $token);
        });
    }

    /**
     * @throws CalendarRefused
     */
    public function rename(RenameFeedInput $input): CalendarFeed
    {
        $feed = $this->mine($input->feedId);
        $name = $this->name($input->name);

        return $this->store->transaction(static function () use ($feed, $name): CalendarFeed {
            $feed->rename($name);

            return $feed;
        });
    }

    /**
     * @throws CalendarRefused
     */
    public function revoke(RevokeFeedInput $input): CalendarFeed
    {
        $feed = $this->mine($input->feedId);

        return $this->store->transaction(function () use ($feed): CalendarFeed {
            $feed->revoke($this->now());

            return $feed;
        });
    }

    /**
     * @throws CalendarRefused
     */
    private function mine(string $id): CalendarFeed
    {
        $userId = $this->me();
        $feed = $this->store->feed($id);
        if (!$feed instanceof CalendarFeed || $feed->userId() !== $userId) {
            throw CalendarRefused::notFound();
        }

        return $feed;
    }

    /**
     * @throws CalendarRefused
     */
    private function me(): string
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw CalendarRefused::forbidden();
        }

        return $userId;
    }

    /**
     * Now in UTC, as the feeds are stored: a database time keeps no zone.
     */
    private function now(): \DateTimeImmutable
    {
        return $this->clock->clock()->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    private function name(string $name): string
    {
        try {
            return CalendarFeed::validName($name);
        } catch (\InvalidArgumentException) {
            throw CalendarRefused::invalid('name_invalid', 'name', \sprintf('The name has 1 to %d characters.', CalendarFeed::NAME_MAX_LENGTH));
        }
    }
}
