<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Integration\Reading;

use Foresi\Attachment\Application\AttachmentFiles;
use Foresi\Attachment\Application\Attachments;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\ReceivedDocuments\Application\PurchasingUnit;
use Foresi\ReceivedDocuments\Application\Reading\DocumentReadings;
use Foresi\ReceivedDocuments\Application\Reading\ReadAgain;
use Foresi\ReceivedDocuments\Application\Reading\ReadDocument;
use Foresi\ReceivedDocuments\Application\Reading\ReadingApplication;
use Foresi\ReceivedDocuments\Application\Reading\ReadingMerge;
use Foresi\ReceivedDocuments\Application\Reading\ReadReceivedDocument;
use Foresi\ReceivedDocuments\Application\ReceivedDocuments;
use Foresi\ReceivedDocuments\Application\ReceivedDocumentWarning;
use Foresi\ReceivedDocuments\Contract\Reading\DocumentFile;
use Foresi\ReceivedDocuments\Contract\Reading\DocumentReader;
use Foresi\ReceivedDocuments\Contract\Reading\ReaderFailed;
use Foresi\ReceivedDocuments\Contract\Reading\ReadField;
use Foresi\ReceivedDocuments\Contract\Reading\Reading;
use Foresi\ReceivedDocuments\Contract\Reading\ReadValue;
use Foresi\ReceivedDocuments\Domain\DocumentReading;
use Foresi\ReceivedDocuments\Domain\DocumentSource;
use Foresi\ReceivedDocuments\Domain\ReadingState;
use Foresi\ReceivedDocuments\Infrastructure\Messenger\ReadReceivedDocumentHandler;
use Foresi\Tests\ReceivedDocuments\Application\PurchasingTestCase;
use Foresi\Tests\Support\IsdocFixtures;
use Foresi\Tests\Support\ReceivedDocuments\StubFallbackReader;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ReadDocumentTest extends PurchasingTestCase
{
    public function testUploadQueuesOneReading(): void
    {
        $uploaded = $this->uploads()->upload([$this->file('%PDF-1.4 first', 'first.pdf'), $this->file('%PDF-1.4 second', 'second.pdf')]);

        $messages = $this->queued();
        self::assertCount(2, $messages);
        foreach ($uploaded as $index => $file) {
            $document = $this->document((string) $file->documentId);
            $message = $messages[$index];
            self::assertSame($document->id(), $message->documentId);
            self::assertSame($document->version(), $message->baseVersion, 'The version after the original was attached.');
            self::assertFalse($message->manual);
            self::assertNotNull($message->correlationId);
            self::assertSame(DocumentSource::Upload, $document->source());
            $reading = $this->reading($document->id());
            self::assertSame(ReadingState::Reading, $reading->state());
            self::assertSame($document->version(), $reading->baseVersion());
        }
    }

    public function testReaderFailureDoesNotStopTheOthers(): void
    {
        $message = $this->uploaded();

        $this->readDocument(
            $this->stub('isdoc', static fn (): never => throw new ReaderFailed('The file is corrupt.')),
            $this->stub('payment_qr', static fn (): Reading => new Reading([
                new ReadValue(ReadField::Total, 12_100, 'payment_qr'),
                new ReadValue(ReadField::VariableSymbol, '2026077', 'payment_qr'),
                new ReadValue(ReadField::BankAccount, 'CZ6508000000192000145399', 'payment_qr'),
            ])),
        )->read($message);

        $reading = $this->reading($message->documentId);
        self::assertSame(ReadingState::Read, $reading->state());
        self::assertSame(['payment_qr'], array_values(array_unique(array_map(static fn (ReadValue $value): string => $value->source, $reading->reading()->values))));
        self::assertSame(['variable_symbol', 'total'], $reading->applied());
        $document = $this->document($message->documentId);
        self::assertSame('2026077', $document->variableSymbol());
        self::assertSame(12_100, $document->total()?->minor());
    }

    public function testNothingFoundAndFailedStates(): void
    {
        $nothing = $this->uploaded('%PDF-1.4 nothing');
        $this->readDocument($this->stub('isdoc', static fn (): ?Reading => null))->read($nothing);
        $found = $this->reading($nothing->documentId);
        self::assertSame(ReadingState::NothingFound, $found->state());
        self::assertNull($found->failure());
        self::assertNotNull($found->finishedAt());

        $failed = $this->uploaded('%PDF-1.4 failed');
        $this->readDocument($this->stub('isdoc', static fn (): never => throw new ReaderFailed('The file is corrupt.')))->read($failed);
        $reading = $this->reading($failed->documentId);
        self::assertSame(ReadingState::Failed, $reading->state());
        self::assertSame(DocumentReading::READER_FAILED, $reading->failure());
        self::assertSame($failed->baseVersion, $this->document($failed->documentId)->version(), 'Nothing was saved.');
    }

    public function testAReaderThatDoesNotSupportTheFileOrRunsOnlyOnRequestIsSkipped(): void
    {
        $message = $this->uploaded();
        /** @var \ArrayObject<int, string> $asked */
        $asked = new \ArrayObject();
        $readers = [
            $this->stub('isdoc', static function () use ($asked): ?Reading {
                $asked[] = 'isdoc';

                return null;
            }, supports: false),
            $this->stub('ai', static function () use ($asked): ?Reading {
                $asked[] = 'ai';

                return null;
            }, automatic: false),
        ];

        $this->readDocument(...$readers)->read($message);
        self::assertSame([], $asked->getArrayCopy());

        $this->reading($message->documentId)->start($message->baseVersion, new \DateTimeImmutable());
        $this->em->flush();
        $this->readDocument(...$readers)->read(new ReadReceivedDocument($message->documentId, $message->baseVersion, true, null));
        self::assertSame(['ai'], $asked->getArrayCopy(), 'A person asked, so the reader that waits for a request runs too.');
    }

    public function testFallbackReaderSkippedWhenStructuredReadersFoundTheKeyFields(): void
    {
        $message = $this->uploaded();
        /** @var \ArrayObject<int, string> $asked */
        $asked = new \ArrayObject();

        $this->readDocument(
            $this->stub('isdoc', fn (): Reading => $this->keyFieldsReading()),
            $this->stub('ai', static function () use ($asked): ?Reading {
                $asked[] = 'ai';

                return null;
            }, fallback: true),
            $this->stub('later', static function () use ($asked): ?Reading {
                $asked[] = 'later';

                return null;
            }),
        )->read($message);

        self::assertSame(['later'], $asked->getArrayCopy(), 'The total, the issue date and the supplier are there, so the fallback reader stays out, and the readers after it still run.');
    }

    public function testFallbackReaderRunsWhenAKeyFieldIsMissing(): void
    {
        $message = $this->uploaded();
        /** @var \ArrayObject<int, string> $asked */
        $asked = new \ArrayObject();
        $fallback = $this->stub('ai', static function () use ($asked): ?Reading {
            $asked[] = 'ai';

            return null;
        }, fallback: true);
        $without = static fn (ReadField $field): Reading => new Reading(array_filter(
            [new ReadValue(ReadField::Total, 12_100, 'isdoc'), new ReadValue(ReadField::IssueDate, '2026-10-28', 'isdoc'), new ReadValue(ReadField::SupplierVatId, 'DE123456789', 'isdoc')],
            static fn (ReadValue $value): bool => $value->field !== $field,
        ));

        foreach ([ReadField::Total, ReadField::IssueDate, ReadField::SupplierVatId] as $missing) {
            $asked->exchangeArray([]);
            $this->reading($message->documentId)->start($message->baseVersion, new \DateTimeImmutable());
            $this->em->flush();
            $this->readDocument($this->stub('isdoc', static fn (): Reading => $without($missing)), $fallback)->read($message);
            self::assertSame(['ai'], $asked->getArrayCopy(), 'Without '.$missing->value.' the fallback reader runs.');
        }

        $asked->exchangeArray([]);
        $this->reading($message->documentId)->start($message->baseVersion, new \DateTimeImmutable());
        $this->em->flush();
        $this->readDocument($fallback)->read($message);
        self::assertSame(['ai'], $asked->getArrayCopy(), 'Nothing read before it.');
    }

    public function testFallbackReaderRunsWhenAPersonAsks(): void
    {
        $message = $this->uploaded();
        /** @var \ArrayObject<int, string> $asked */
        $asked = new \ArrayObject();

        $this->reading($message->documentId)->start($message->baseVersion, new \DateTimeImmutable());
        $this->em->flush();
        $this->readDocument(
            $this->stub('isdoc', fn (): Reading => $this->keyFieldsReading()),
            $this->stub('ai', static function () use ($asked): ?Reading {
                $asked[] = 'ai';

                return null;
            }, fallback: true),
        )->read(new ReadReceivedDocument($message->documentId, $message->baseVersion, true, null));

        self::assertSame(['ai'], $asked->getArrayCopy());
    }

    public function testAiFailureKeepsStructuredValues(): void
    {
        $message = $this->uploaded();
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$level, $context];
            }
        };
        $this->logger = $logger;

        $this->readDocument(
            $this->stub('isdoc', static fn (): Reading => new Reading([
                new ReadValue(ReadField::SupplierNumber, 'FV-2026-77', 'isdoc'),
                new ReadValue(ReadField::Total, 12_100, 'isdoc'),
            ])),
            $this->stub('ai', static fn (): never => throw new ReaderFailed('provider_unavailable'), fallback: true),
        )->read($message);
        self::assertSame([['warning', ['reader' => 'ai', 'code' => 'provider_unavailable']]], $logger->records, 'The failure is logged with its reader and its code only.');

        $reading = $this->reading($message->documentId);
        self::assertSame(ReadingState::Read, $reading->state());
        self::assertNull($reading->failure());
        $document = $this->document($message->documentId);
        self::assertSame('FV-2026-77', $document->supplierNumber());
        self::assertSame(12_100, $document->total()?->minor());
    }

    public function testRunningTwiceAppliesOnce(): void
    {
        $message = $this->uploaded();
        $reader = $this->readDocument($this->stub('isdoc', fn (): Reading => $this->isdocReading()));

        $reader->read($message);
        $first = $this->reading($message->documentId);
        $values = $first->reading()->toArray();
        $applied = $first->applied();
        $reader->read($message);

        $again = $this->reading($message->documentId);
        self::assertSame($values, $again->reading()->toArray());
        self::assertSame($applied, $again->applied());
        self::assertSame($message->baseVersion + 1, $this->document($message->documentId)->version(), 'Applied once.');
    }

    public function testAMessageForAReadingThatIsNotDueReadsNothing(): void
    {
        $message = $this->uploaded();
        $asked = 0;
        $reader = $this->stub('isdoc', static function () use (&$asked): ?Reading {
            ++$asked;

            return null;
        });

        $this->readDocument($reader)->read($message);
        $this->readDocument($reader)->read($message);

        self::assertSame(1, $asked, 'The reading finished, so the same message reads nothing again.');
    }

    public function testAReadingThatFinishesWhileTheFileIsReadIsLeftAsItIs(): void
    {
        $message = $this->uploaded();

        $this->readDocument($this->stub('isdoc', function () use ($message): Reading {
            // Something else settles the reading while the file is being read.
            $this->reading($message->documentId)->fail(DocumentReading::UNEXPECTED, new \DateTimeImmutable('2026-11-10 09:00:00'));
            $this->em->flush();

            return $this->isdocReading();
        }))->read($message);

        $reading = $this->reading($message->documentId);
        self::assertSame(ReadingState::Failed, $reading->state());
        self::assertSame(DocumentReading::UNEXPECTED, $reading->failure());
        self::assertSame($message->baseVersion, $this->document($message->documentId)->version(), 'Nothing was applied.');
    }

    public function testAMessageWithoutACorrelationIsReadToo(): void
    {
        $uploaded = $this->uploaded();
        $message = new ReadReceivedDocument($uploaded->documentId, $uploaded->baseVersion, false, null);

        $this->readDocument($this->stub('isdoc', fn (): Reading => $this->isdocReading()))->read($message);

        self::assertSame(ReadingState::Read, $this->reading($message->documentId)->state());
        self::assertSame(12_100, $this->document($message->documentId)->total()?->minor());
    }

    public function testAMessageDoesNotKeepWhatWasTypedUnlessItSays(): void
    {
        self::assertFalse(new ReadReceivedDocument('a', 1, false, null)->keepTyped);
        self::assertTrue(new ReadReceivedDocument('a', 1, false, null, true)->keepTyped);
    }

    public function testRaceWithAPerson(): void
    {
        $message = $this->uploaded();
        $person = $this->input(supplierNumber: 'TYPED-BY-PERSON', base: 50_000);

        $this->readDocument($this->stub('isdoc', function () use ($message, $person): Reading {
            // A person saves the draft while the file is being read.
            $this->editing()->edit($message->documentId, $person);

            return $this->isdocReading();
        }))->read($message);

        $document = $this->document($message->documentId);
        self::assertSame('TYPED-BY-PERSON', $document->supplierNumber());
        self::assertSame(60_500, $document->total()?->minor());
        self::assertSame($this->supplier->id(), $document->supplierParty()?->id());
        $reading = $this->reading($message->documentId);
        self::assertSame(ReadingState::Read, $reading->state());
        self::assertSame([], $reading->applied());
        self::assertContains('supplier_number', $reading->suggested());
        self::assertContains('total', $reading->suggested());
        self::assertSame('FV-2026-77', $reading->reading()->get(ReadField::SupplierNumber)?->value, 'The reading stays to be offered.');
    }

    public function testAnUnsupportedCurrencyLeavesTheAmountsAsSuggestions(): void
    {
        $message = $this->uploaded();

        $this->readDocument($this->stub('isdoc', static fn (): Reading => new Reading([
            new ReadValue(ReadField::SupplierNumber, 'FV-2026-77', 'isdoc'),
            new ReadValue(ReadField::Currency, 'GBP', 'isdoc'),
            new ReadValue(ReadField::Total, 12_100, 'isdoc'),
            new ReadValue(ReadField::VatRecap, [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_100]], 'isdoc'),
        ])))->read($message);

        $reading = $this->reading($message->documentId);
        $document = $this->document($message->documentId);
        self::assertSame(['supplier_number'], $reading->applied());
        self::assertSame(['total', 'vat_recap'], $reading->suggested());
        self::assertContains(ReceivedDocumentWarning::READING_PARTLY_APPLIED, $reading->warnings());
        self::assertSame('FV-2026-77', $document->supplierNumber());
        self::assertNotSame(12_100, $document->total()?->minor(), 'GBP amounts are never taken as CZK.');
        self::assertTrue($document->vatRecap()->isEmpty());
    }

    public function testReadingAgainNeverReplacesWhatAPersonTyped(): void
    {
        $message = $this->uploaded();
        $this->readDocument($this->stub('isdoc', static fn (): ?Reading => null))->read($message);
        $this->editing()->edit($message->documentId, $this->input(supplierNumber: 'TYPED-BY-PERSON', base: 50_000));

        self::getContainer()->get(ReadAgain::class)->readAgain($message->documentId);
        $again = $this->queued()[1];
        self::assertTrue($again->keepTyped, 'The draft was touched since its last reading.');
        $this->readDocument($this->stub('isdoc', fn (): Reading => $this->isdocReading()))->read($again);

        $document = $this->document($message->documentId);
        self::assertSame('TYPED-BY-PERSON', $document->supplierNumber());
        self::assertSame(60_500, $document->total()?->minor(), 'The typed total stays.');
        $reading = $this->reading($message->documentId);
        self::assertSame([], $reading->applied());
        self::assertContains('total', $reading->suggested());
        self::assertSame(12_100, $reading->reading()->get(ReadField::Total)?->value, 'The read total is offered.');
    }

    public function testReadingAgainFillsOnlyTheEmptyFieldsOfATouchedDraft(): void
    {
        $message = $this->uploaded();
        $this->readDocument($this->stub('isdoc', static fn (): ?Reading => null))->read($message);
        $this->editing()->edit($message->documentId, $this->input(supplierNumber: 'TYPED-BY-PERSON', taxPoint: null, base: 50_000));

        self::getContainer()->get(ReadAgain::class)->readAgain($message->documentId);
        $this->readDocument($this->stub('isdoc', static fn (): Reading => new Reading([
            new ReadValue(ReadField::SupplierNumber, 'FV-2026-77', 'isdoc'),
            new ReadValue(ReadField::TaxPointDate, '2026-10-27', 'isdoc'),
        ])))->read($this->queued()[1]);

        $document = $this->document($message->documentId);
        self::assertSame('TYPED-BY-PERSON', $document->supplierNumber());
        self::assertSame('2026-10-27', $document->taxPointDate()?->format('Y-m-d'));
        $reading = $this->reading($message->documentId);
        self::assertSame(['tax_point_date'], $reading->applied());
        self::assertSame(['supplier_number'], $reading->suggested());
    }

    public function testReadingAgainAppliesAsUsualToADraftNobodyTouched(): void
    {
        $message = $this->uploaded();
        $this->readDocument($this->stub('isdoc', fn (): Reading => $this->isdocReading()))->read($message);
        $taken = $this->document($message->documentId)->version();

        self::getContainer()->get(ReadAgain::class)->readAgain($message->documentId);
        $again = $this->queued()[1];
        self::assertFalse($again->keepTyped, 'Only the reading itself changed the draft.');
        self::assertSame($taken, $again->baseVersion);
        $this->readDocument($this->stub('isdoc', static fn (): Reading => new Reading([new ReadValue(ReadField::Total, 24_200, 'isdoc')])))->read($again);

        self::assertSame(24_200, $this->document($message->documentId)->total()?->minor());
    }

    public function testTheQueueGivingUpMarksTheReadingFailed(): void
    {
        $message = $this->uploaded();

        // The last of the three retries of the transport.
        $last = new Envelope($message, [new RedeliveryStamp(3)]);
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new WorkerMessageFailedEvent($last, 'async', new \RuntimeException('Something broke.')));

        $reading = $this->reading($message->documentId);
        self::assertSame(ReadingState::Failed, $reading->state());
        self::assertSame(DocumentReading::UNEXPECTED, $reading->failure());
    }

    public function testGivingUpWorksAfterTheFailureClosedTheEntityManager(): void
    {
        $message = $this->uploaded();
        $this->em->close();

        $this->giveUp($message);

        $reading = $this->reading($message->documentId);
        self::assertSame(ReadingState::Failed, $reading->state());
        self::assertSame(DocumentReading::UNEXPECTED, $reading->failure());
    }

    public function testGivingUpNeverThrowsIntoTheWorker(): void
    {
        $message = $this->uploaded();
        // A correlation id the audit refuses makes marking the reading throw.
        $broken = new ReadReceivedDocument($message->documentId, $message->baseVersion, false, str_repeat('x', 65));

        $this->giveUp($broken);

        self::assertSame(ReadingState::Reading, $this->reading($message->documentId)->state());
    }

    public function testAReadingChangedAfterTheLockOfItsDocumentIsStored(): void
    {
        $message = $this->uploaded();
        $this->readDocument($this->stub('isdoc', static fn (): ?Reading => null))->read($message);
        $this->em->clear();
        $documents = self::getContainer()->get(ReceivedDocuments::class);
        $readings = self::getContainer()->get(DocumentReadings::class);

        // Read again, as a person asks: the document locked, then its reading changed.
        self::getContainer()->get(PurchasingUnit::class)->run(static function () use ($documents, $readings, $message): void {
            $readings->find($message->documentId);
            $document = $documents->lock($message->documentId);
            $readings->find($message->documentId)?->start($document->version(), new \DateTimeImmutable('2026-11-10 10:00:00'));
        });

        $reading = $this->reading($message->documentId);
        self::assertSame(ReadingState::Reading, $reading->state());
        self::assertSame(2, $reading->attempts());
    }

    public function testALockAfterAReadingWasAddedIsRefused(): void
    {
        $id = $this->recording()->create($this->input())->document->id();
        $this->em->clear();
        $documents = self::getContainer()->get(ReceivedDocuments::class);
        $readings = self::getContainer()->get(DocumentReadings::class);

        $this->expectException(\LogicException::class);
        self::getContainer()->get(PurchasingUnit::class)->run(static function () use ($documents, $readings, $id): void {
            $document = $documents->lock($id);
            $readings->add(new DocumentReading($document, $document->version(), new \DateTimeImmutable('2026-11-10 10:00:00')));
            // Detached by a second lock, the new reading would never be stored.
            $documents->lock($id);
        });
    }

    public function testTheWorkerReadsAnIsdocFileIntoTheDraft(): void
    {
        $uploaded = $this->uploads()->upload([$this->file(IsdocFixtures::invoice(), 'invoice.isdoc')])[0];
        self::assertTrue($uploaded->isDrafted(), (string) $uploaded->refusal);

        self::getContainer()->get(ReadReceivedDocumentHandler::class)($this->queued()[0]);

        $document = $this->document((string) $uploaded->documentId);
        self::assertSame(1_210_000, $document->total()?->minor());
        self::assertSame(1_000_000, $document->base()->minor());
        $reading = $this->reading($document->id());
        self::assertSame(ReadingState::Read, $reading->state());
        self::assertSame('isdoc', $reading->reading()->get(ReadField::Total)?->source);
    }

    public function testTheWorkerLeavesACorruptPdfAsItIsAndSaysTheReadingFailed(): void
    {
        // Not a PDF the readers can open, though it says it is one.
        $message = $this->uploaded();

        self::getContainer()->get(ReadReceivedDocumentHandler::class)($message);

        $reading = $this->reading($message->documentId);
        self::assertSame(ReadingState::Failed, $reading->state());
        self::assertSame(DocumentReading::READER_FAILED, $reading->failure());
        self::assertSame($message->baseVersion, $this->document($message->documentId)->version());
    }

    /**
     * What the worker announces after the last retry of the message.
     */
    private function giveUp(ReadReceivedDocument $message): void
    {
        $last = new Envelope($message, [new RedeliveryStamp(3)]);
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new WorkerMessageFailedEvent($last, 'async', new \RuntimeException('Something broke.')));
    }

    private function keyFieldsReading(): Reading
    {
        return new Reading([
            new ReadValue(ReadField::Total, 12_100, 'isdoc'),
            new ReadValue(ReadField::IssueDate, '2026-10-28', 'isdoc'),
            new ReadValue(ReadField::SupplierRegistrationNumber, '12345678', 'isdoc'),
        ]);
    }

    private function isdocReading(): Reading
    {
        return new Reading([
            new ReadValue(ReadField::SupplierNumber, 'FV-2026-77', 'isdoc'),
            new ReadValue(ReadField::IssueDate, '2026-10-28', 'isdoc'),
            new ReadValue(ReadField::Currency, 'CZK', 'isdoc'),
            new ReadValue(ReadField::Total, 12_100, 'isdoc'),
            new ReadValue(ReadField::VatRecap, [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_100]], 'isdoc'),
        ]);
    }

    /**
     * Uploads one file and returns the message its upload queued.
     */
    private function uploaded(string $content = '%PDF-1.4 An example invoice'): ReadReceivedDocument
    {
        $before = \count($this->queued());
        $this->draft($content);

        return $this->queued()[$before];
    }

    /**
     * @return list<ReadReceivedDocument>
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

    /**
     * The job with these readers in place of the installed ones.
     */
    private ?LoggerInterface $logger = null;

    private function readDocument(DocumentReader ...$readers): ReadDocument
    {
        $container = self::getContainer();

        return new ReadDocument(
            $readers,
            new ReadingMerge(),
            $container->get(ReceivedDocuments::class),
            $container->get(DocumentReadings::class),
            $container->get(ReadingApplication::class),
            $container->get(Attachments::class),
            $container->get(AttachmentFiles::class),
            $container->get(PurchasingUnit::class),
            $container->get(AuditCorrelation::class),
            $container->get(ClockInterface::class),
            $this->logger ?? $container->get(LoggerInterface::class),
        );
    }

    /**
     * @param \Closure(DocumentFile): ?Reading $read
     */
    private function stub(string $source, \Closure $read, bool $supports = true, bool $automatic = true, bool $fallback = false): DocumentReader
    {
        if ($fallback) {
            return new StubFallbackReader($source, $read, $supports, $automatic);
        }

        return new readonly class($source, $read, $supports, $automatic) implements DocumentReader {
            /**
             * @param \Closure(DocumentFile): ?Reading $reader
             */
            public function __construct(private string $key, private \Closure $reader, private bool $takes, private bool $runs)
            {
            }

            public function source(): string
            {
                return $this->key;
            }

            public function supports(DocumentFile $file): bool
            {
                return $this->takes;
            }

            public function automatic(): bool
            {
                return $this->runs;
            }

            public function read(DocumentFile $file): ?Reading
            {
                return ($this->reader)($file);
            }
        };
    }
}
