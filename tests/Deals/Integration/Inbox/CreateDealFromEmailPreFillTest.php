<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Integration\Inbox;

use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Integration\Inbox\CreateDealFromEmailPreFill;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;
use Foresi\Tests\Inbox\Support\ItemViews;
use PHPUnit\Framework\TestCase;

/**
 * The deal starts as the mail: its subject as the title, and the company and the person of the
 * item as the clients to pick from, the company first.
 */
final class CreateDealFromEmailPreFillTest extends TestCase
{
    use ItemViews;

    private const string PERSON = '0192f0a0-2222-7000-8000-000000000001';
    private const string COMPANY = '0192f0a0-2222-7000-8000-000000000002';
    private const string GONE = '0192f0a0-2222-7000-8000-000000000003';

    public function testTheTitleIsTheSubjectTrimmedAndCut(): void
    {
        $prefill = $this->prefillFor([]);

        $values = $prefill->prefill($this->itemView(subject: '  '.str_repeat('ž', Deal::MAX_TITLE + 20).'  '))->values;

        self::assertSame(str_repeat('ž', Deal::MAX_TITLE), $values['title']);
        self::assertTrue($values['markDone']);
    }

    public function testTheCompanyAndThePersonAreOfferedTheCompanyFirstAndPreselected(): void
    {
        $prefill = $this->prefillFor([self::PERSON => false, self::COMPANY => false]);

        $result = $prefill->prefill($this->itemView(contactId: self::PERSON, companyId: self::COMPANY));

        self::assertSame([self::COMPANY => 'Client A Ltd', self::PERSON => 'Client A'], $result->choices['clientPartyId'], 'The company comes first.');
        self::assertSame(self::COMPANY, $result->values['clientPartyId']);
    }

    public function testAPersonWithoutACompanyIsTheOnlyClient(): void
    {
        $prefill = $this->prefillFor([self::PERSON => false, self::COMPANY => false]);

        $result = $prefill->prefill($this->itemView(contactId: self::PERSON));

        self::assertSame(['clientPartyId' => [self::PERSON => 'Client A']], $result->choices);
        self::assertSame(self::PERSON, $result->values['clientPartyId']);
    }

    public function testAnArchivedOrUnknownContactIsNotOffered(): void
    {
        $prefill = $this->prefillFor([self::PERSON => true]);

        $result = $prefill->prefill($this->itemView(contactId: self::PERSON, companyId: self::GONE));

        self::assertSame([], $result->choices);
        self::assertArrayNotHasKey('clientPartyId', $result->values);
    }

    /**
     * @param array<string, bool> $parties archived, by the id of each party that exists
     */
    private function prefillFor(array $parties): CreateDealFromEmailPreFill
    {
        $directory = self::createStub(PartyDirectory::class);
        $directory->method('find')->willReturnCallback(static fn (string $id): ?PartySummary => \array_key_exists($id, $parties)
            ? new PartySummary($id, self::COMPANY === $id ? ContactKind::Company : ContactKind::Person, self::COMPANY === $id ? 'Client A Ltd' : 'Client A', null, null, [], null, null, [], $parties[$id])
            : null);

        return new CreateDealFromEmailPreFill($directory);
    }
}
