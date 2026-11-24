<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Integration\Reading;

use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\Reading\AcceptReadValues;
use Foresi\Purchasing\Application\Reading\ReadAgain;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\ReadingState;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentContent;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Purchasing\Infrastructure\Messenger\ReadReceivedDocumentHandler;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Purchasing\Application\PurchasingTestCase;
use Foresi\Tests\Support\IsdocFixtures;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A person asks for the reading of a draft again, and takes the values it
 * left as suggestions.
 */
final class ReadAgainTest extends PurchasingTestCase
{
    public function testQueuesAManualReading(): void
    {
        $id = $this->draft();
        // The corrupt file fails to read, so the reading is finished.
        $this->handle($this->queued()[0]);
        self::assertSame(ReadingState::Failed, $this->reading($id)->state());
        $version = $this->document($id)->version();

        $this->readAgain()->readAgain($id);

        $reading = $this->reading($id);
        self::assertSame(ReadingState::Reading, $reading->state());
        self::assertSame(2, $reading->attempts());
        self::assertSame($version, $reading->baseVersion());
        self::assertNull($reading->failure());
        $messages = $this->queued();
        self::assertCount(2, $messages);
        self::assertSame($id, $messages[1]->documentId);
        self::assertSame($version, $messages[1]->baseVersion);
        self::assertTrue($messages[1]->manual, 'A person asked.');
    }

    public function testRefusesWhileReading(): void
    {
        $id = $this->draft();

        $this->assertRefused(ReceivedDocumentRefused::READING_IN_PROGRESS, fn () => $this->readAgain()->readAgain($id));
        self::assertCount(1, $this->queued());

        // A reading asked for more than ten minutes ago is stuck: it may be asked for again.
        $this->clock->modify('+11 minutes');
        $this->readAgain()->readAgain($id);
        self::assertCount(2, $this->queued());
        self::assertSame(2, $this->reading($id)->attempts());
    }

    public function testRefusesARecordedDocument(): void
    {
        $id = $this->recording()->create($this->input())->document->id();

        $this->assertRefused(ReceivedDocumentRefused::RECORDED, fn () => $this->readAgain()->readAgain($id));
        self::assertSame([], $this->queued());
    }

    public function testADraftWithoutAFileIsRefused(): void
    {
        // A draft typed by hand, as the API may leave one.
        $document = new ReceivedDocument(ReceivedDocumentContent::blank(new \DateTimeImmutable('2026-11-10')), $this->owner->user()->id(), new \DateTimeImmutable('2026-11-10 09:00:00'));
        $this->em->persist($document);
        $this->em->flush();
        $id = $document->id();

        $this->assertRefused(ReceivedDocumentRefused::NO_ORIGINAL_FILE, fn () => $this->readAgain()->readAgain($id));
    }

    public function testGuestIsRefused(): void
    {
        $id = $this->draft();
        $this->signIn($this->guest);

        $this->expectException(PurchasingNotAllowed::class);
        $this->readAgain()->readAgain($id);
    }

    public function testAcceptsTheChosenSuggestionsOnly(): void
    {
        $id = $this->isdocDraftEditedFirst();
        $before = $this->document($id);
        self::assertSame('TYPED-BY-PERSON', $before->supplierNumber());

        $saved = $this->accepting()->accept($id, [ReadField::Total, ReadField::VatRecap], $before->version());

        $document = $this->document($id);
        self::assertSame($before->version() + 1, $saved->document->version());
        self::assertSame(1_210_000, $document->total()?->minor());
        self::assertSame(1_000_000, $document->base()->minor());
        self::assertSame('TYPED-BY-PERSON', $document->supplierNumber(), 'Not chosen, so kept.');
        self::assertSame($this->supplier->id(), $document->supplierParty()?->id());
    }

    public function testAskingAgainKeepsWhatAPersonTypedBeforeAReadingThatTookNothing(): void
    {
        $id = $this->isdocDraftEditedFirst();

        $this->readAgain()->readAgain($id);

        $messages = $this->queued();
        self::assertTrue($messages[\count($messages) - 1]->keepTyped);
    }

    public function testAskingAgainOfADraftNobodyTouchedKeepsNothing(): void
    {
        $uploaded = $this->uploads()->upload([$this->file(IsdocFixtures::invoice(), 'invoice.isdoc')])[0];
        $id = (string) $uploaded->documentId;
        $this->handle($this->queued()[0]);
        $this->em->clear();

        $this->readAgain()->readAgain($id);

        $messages = $this->queued();
        self::assertFalse($messages[\count($messages) - 1]->keepTyped);
    }

    public function testAnUnansweredReadingMayBeAskedAgainWhenItIsStuck(): void
    {
        $document = new ReceivedDocument(ReceivedDocumentContent::blank(new \DateTimeImmutable('2026-11-02')), Id::generate(), new \DateTimeImmutable('2026-11-10 09:00:00'));
        $reading = new DocumentReading($document, 1, new \DateTimeImmutable('2026-11-10 09:00:00'));

        self::assertFalse(ReadAgain::mayStart($reading, new \DateTimeImmutable('2026-11-10 09:09:59')));
        self::assertTrue(ReadAgain::mayStart($reading, new \DateTimeImmutable('2026-11-10 09:10:00')));
        self::assertTrue(ReadAgain::mayStart($reading, new \DateTimeImmutable('2026-11-10 09:10:01')));
    }

    public function testAcceptingAfterAnotherSaveIsRefusedAsStale(): void
    {
        $id = $this->isdocDraftEditedFirst();
        $version = $this->document($id)->version();

        $this->assertRefused(ReceivedDocumentRefused::STALE, fn (): \Foresi\Purchasing\Application\SavedReceivedDocument => $this->accepting()->accept($id, [ReadField::Total], $version - 1));
    }

    public function testAcceptingAsAGuestIsRefused(): void
    {
        $id = $this->isdocDraftEditedFirst();
        $version = $this->document($id)->version();
        $this->signIn($this->guest);

        $this->expectException(PurchasingNotAllowed::class);
        $this->accepting()->accept($id, [ReadField::Total], $version);
    }

    /**
     * An uploaded ISDOC file a person saved with values of their own before it was read.
     */
    private function isdocDraftEditedFirst(): string
    {
        $uploaded = $this->uploads()->upload([$this->file(IsdocFixtures::invoice(), 'invoice.isdoc')])[0];
        $id = (string) $uploaded->documentId;
        $this->editing()->edit($id, $this->input(supplierNumber: 'TYPED-BY-PERSON', base: 50_000));
        $this->handle($this->queued()[0]);
        $reading = $this->reading($id);
        self::assertSame([], $reading->applied());
        self::assertContains('total', $reading->suggested());

        return $id;
    }

    private function readAgain(): ReadAgain
    {
        return self::getContainer()->get(ReadAgain::class);
    }

    private function accepting(): AcceptReadValues
    {
        return self::getContainer()->get(AcceptReadValues::class);
    }

    private function handle(ReadReceivedDocument $message): void
    {
        self::getContainer()->get(ReadReceivedDocumentHandler::class)($message);
    }

    /**
     * @return list<ReadReceivedDocument>
     *
     * @phpstan-impure
     */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ReadReceivedDocument) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function reading(string $documentId): DocumentReading
    {
        $this->em->clear();

        return $this->em->find(DocumentReading::class, $documentId) ?? throw new \LogicException('No reading.');
    }
}
