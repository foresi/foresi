<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Domain\FeedToken;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;

// The world of the activities spec: the four types the migration seeds, a client, and a calendar
// feed of the owner whose address the spec knows, so it reads what the calendar app would.
$createParty->createFrom($party(PartyKind::Company, 'Example Roofs s.r.o.', [PartyRole::Customer], 'roofs@example.com', 4, '12345679', 'CZ12345679'));
\assert(isset($owner));
$entityManager->persist(new CalendarFeed($owner->id(), 'Work', FeedToken::hash('e2e-activities-feed'), new DateTimeImmutable()));
$entityManager->flush();
