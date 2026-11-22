<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Activities;

use Foresi\Activities\Application\ActivityTargets;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Integration\Activities\ContactActivityTarget;
use Foresi\Tests\Party\Application\Query\ContactTestCase;

/**
 * Activities about a contact: an active contact is seen and linked by the
 * owners and members; an archived one counts as gone, and a guest sees none.
 */
final class ContactActivityTargetTest extends ContactTestCase
{
    public function testActiveContactIsSeenAndLinkedWithNameAndUrl(): void
    {
        $contact = $this->contact('Alpha Example s.r.o.');
        $target = $this->target();

        self::assertSame(['contact'], $target->kinds());
        self::assertTrue($target->canSee('contact', $contact->id()));
        self::assertTrue($target->canLink('contact', $contact->id()));
        self::assertSame('Alpha Example s.r.o.', $target->label('contact', $contact->id()));
        self::assertSame('/contacts/'.$contact->id(), $target->url('contact', $contact->id()));
    }

    public function testArchivedOrUnknownContactIsGone(): void
    {
        $archived = $this->contact('Old Example s.r.o.', archived: true);
        $target = $this->target();

        foreach ([$archived->id(), '0192f1a4-0000-7000-8000-000000000000', 'not-an-id'] as $id) {
            self::assertFalse($target->canSee('contact', $id), $id);
            self::assertFalse($target->canLink('contact', $id), $id);
            self::assertNull($target->label('contact', $id), $id);
            self::assertNull($target->url('contact', $id), $id);
        }
    }

    public function testOtherKindsAreNotContacts(): void
    {
        $contact = $this->contact('Alpha Example s.r.o.');

        self::assertFalse($this->target()->canSee('deal', $contact->id()));
        self::assertNull($this->target()->label('deal', $contact->id()));
    }

    public function testAGuestSeesNoContact(): void
    {
        $contact = $this->contact('Alpha Example s.r.o.');
        $this->signIn($this->guest);

        self::assertFalse($this->target()->canSee('contact', $contact->id()));
        self::assertNull($this->target()->url('contact', $contact->id()));
    }

    public function testActivitiesLinkToContacts(): void
    {
        self::assertContains('contact', self::getContainer()->get(ActivityTargets::class)->kinds());
    }

    private function target(): ContactActivityTarget
    {
        return new ContactActivityTarget(self::getContainer()->get(PartyDirectory::class), self::getContainer()->get('router'));
    }
}
