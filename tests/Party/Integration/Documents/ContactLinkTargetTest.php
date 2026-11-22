<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Documents;

use Foresi\Documents\Application\LinkTargets;
use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Integration\Documents\ContactLinkTarget;
use Foresi\Tests\Party\Application\Query\ContactTestCase;

/**
 * A document links to a contact; the link shows the contact while it is in
 * the address book and as unavailable once it is archived or gone.
 */
final class ContactLinkTargetTest extends ContactTestCase
{
    public function testActiveContactExistsWithNameAndUrl(): void
    {
        $contact = $this->contact('Alpha Example s.r.o.');
        $target = $this->target();

        self::assertSame(['contact'], $target->kinds());
        self::assertTrue($target->exists('contact', $contact->id()));
        self::assertSame('Alpha Example s.r.o.', $target->label('contact', $contact->id()));
        self::assertSame('/contacts/'.$contact->id(), $target->url('contact', $contact->id()));
    }

    public function testArchivedOrUnknownContactDoesNotExist(): void
    {
        $archived = $this->contact('Old Example s.r.o.', archived: true);
        $target = $this->target();

        foreach ([$archived->id(), '0192f1a4-0000-7000-8000-000000000000', 'not-an-id'] as $id) {
            self::assertFalse($target->exists('contact', $id), $id);
            self::assertNull($target->label('contact', $id), $id);
            self::assertNull($target->url('contact', $id), $id);
        }
    }

    public function testOtherKindsAreNotContacts(): void
    {
        $contact = $this->contact('Alpha Example s.r.o.');

        self::assertFalse($this->target()->exists('deal', $contact->id()));
        self::assertNull($this->target()->label('deal', $contact->id()));
    }

    public function testAGuestSeesNoContact(): void
    {
        $contact = $this->contact('Alpha Example s.r.o.');
        $this->signIn($this->guest);

        $this->expectException(PartyNotAllowed::class);
        $this->target()->exists('contact', $contact->id());
    }

    public function testDocumentsLinksToContacts(): void
    {
        self::assertContains('contact', self::getContainer()->get(LinkTargets::class)->kinds());
    }

    private function target(): ContactLinkTarget
    {
        return new ContactLinkTarget(self::getContainer()->get(PartyDirectory::class), self::getContainer()->get('router'));
    }
}
