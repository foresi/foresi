<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\SendDraft;
use App\Invoicing\Application\SendRecipient;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\PartyEmail;
use Symfony\Component\Clock\Clock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendDraftTest extends SendingTestCase
{
    public function testRecipientsComeFromTheContactForThisFirm(): void
    {
        $other = $this->firm(registrationNumber: '12345687');
        $otherSeries = $this->seriesOf($other);
        $this->em->persist(new PartyEmail($this->customer, 'firm-billing@example.com', EmailPurpose::Invoices, $this->firm));
        $this->em->flush();
        $own = $this->issue->issue($this->input());
        $shared = $this->issue->issue($this->input(overrides: ['firmId' => $other->id(), 'seriesId' => $otherSeries->id()]));

        // The firm's own address replaces the shared one; another firm keeps the shared one.
        self::assertSame([['firm-billing@example.com', 'to', true]], $this->recipientsOf($own));
        self::assertSame([['billing@example.com', 'to', true]], $this->recipientsOf($shared));
        self::assertFalse($this->draft()->for($own)->fromDocument);
    }

    public function testCopyPurposeBecomesCc(): void
    {
        $this->em->persist(new PartyEmail($this->customer, 'accounts@example.com', EmailPurpose::Copy));
        $this->em->flush();
        $invoice = $this->issue->issue($this->input());

        self::assertSame([['billing@example.com', 'to', true], ['accounts@example.com', 'cc', true]], $this->recipientsOf($invoice));
    }

    public function testGeneralOnlyContactFallsBackToTheSnapshot(): void
    {
        $this->em->createQuery('DELETE FROM '.PartyEmail::class.' e')->execute();
        $this->em->persist(new PartyEmail($this->customer, 'office@example.com', EmailPurpose::General));
        $this->em->flush();
        $invoice = $this->issue->issue($this->input());

        $draft = $this->draft()->for($invoice);

        // The general address is no invoicing address, but the document copied it.
        self::assertSame([['office@example.com', 'to', true]], $this->recipientsOf($invoice));
        self::assertTrue($draft->fromDocument);
    }

    public function testACopyOnlyContactSendsToTheDocumentsAddress(): void
    {
        $this->em->createQuery('DELETE FROM '.PartyEmail::class.' e')->execute();
        $this->em->persist(new PartyEmail($this->customer, 'office@example.com', EmailPurpose::General));
        $this->em->persist(new PartyEmail($this->customer, 'accounts@example.com', EmailPurpose::Copy));
        $this->em->flush();
        $invoice = $this->issue->issue($this->input());

        // The document copied the copy address, the first invoicing one; the general one stays out.
        self::assertSame([['accounts@example.com', 'to', true]], $this->recipientsOf($invoice));
        self::assertTrue($this->draft()->for($invoice)->fromDocument);
    }

    public function testACopyOnlyContactKeepsItsCopyBesideTheDocumentsAddress(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->em->createQuery('DELETE FROM '.PartyEmail::class.' e')->execute();
        $this->em->persist(new PartyEmail($this->customer, 'accounts@example.com', EmailPurpose::Copy));
        $this->em->flush();

        // The document still holds the address it was issued with.
        self::assertSame([['billing@example.com', 'to', true], ['accounts@example.com', 'cc', true]], $this->recipientsOf($invoice));
    }

    public function testNoAddressAnywhereLeavesTheRecipientsEmpty(): void
    {
        $this->em->createQuery('DELETE FROM '.PartyEmail::class.' e')->execute();
        $invoice = $this->issue->issue($this->input());

        self::assertSame([], $this->recipientsOf($invoice));
        self::assertFalse($this->draft()->for($invoice)->fromDocument);
    }

    public function testADraftIsInTheDocumentLanguage(): void
    {
        self::getContainer()->get(TranslatorInterface::class)->setLocale('cs');
        $invoice = $this->issue->issue($this->input(overrides: ['language' => DocumentLanguage::English]));

        $draft = $this->draft()->for($invoice);

        self::assertSame('Invoice 20260001', $draft->subject);
        // Filled in and readable, all but the link, which only the sent mail has.
        self::assertStringStartsWith('Based on your order I am sending invoice no. 20260001 for ', $draft->body);
        self::assertStringContainsString('due on 15 Nov 2026.', $draft->body);
        self::assertStringEndsWith('{link}', $draft->body);
        self::assertSame(DocumentLanguage::English, $draft->language);
    }

    public function testTheFirmsOwnTemplateIsUsed(): void
    {
        $templates = self::getContainer()->get(\App\Invoicing\Application\MailTemplates::class);
        $templates->save($this->firm, \App\Invoicing\Domain\MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Doklad {number}', "Dobrý den,\n{customer_name}\n{link}");
        $this->em->flush();
        $invoice = $this->issue->issue($this->input());

        $draft = $this->draft()->for($invoice);

        self::assertSame('Doklad 20260001', $draft->subject);
        self::assertSame("Dobrý den,\nExample Customer a.s.\n{link}", $draft->body);
    }

    public function testTheSenderAndTheCopyAddressAreTheFirms(): void
    {
        $this->contactEmail('office@example.com');
        $invoice = $this->issue->issue($this->input());

        $draft = $this->draft()->for($invoice);

        self::assertTrue($draft->hasServer);
        self::assertSame('Example Firm', $draft->fromName);
        self::assertSame('invoices@example.com', $draft->fromAddress);
        self::assertSame('office@example.com', $draft->copyAddress);
        self::assertNull($draft->lastSentAt);
    }

    public function testWithoutAServerTheDraftSaysSo(): void
    {
        $this->em->createQuery('DELETE FROM '.FirmMailServer::class.' s')->execute();
        $invoice = $this->issue->issue($this->input());

        $draft = $this->draft()->for($invoice);

        self::assertFalse($draft->hasServer);
        self::assertNull($draft->fromAddress);
    }

    public function testTheLastSendIsTheNewestThatWentOut(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->sent($invoice);
        $this->worker->run();
        self::mockTime('2026-11-04 08:00:00');
        // Queued, not yet out: it is no "sent" date.
        $this->sent($invoice);

        $draft = $this->draft()->for($invoice);

        self::assertSame('2026-11-03 10:00:00', $draft->lastSentAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-04 08:00:00', Clock::get()->now()->format('Y-m-d H:i:s'));
    }

    private function draft(): SendDraft
    {
        return self::getContainer()->get(SendDraft::class);
    }

    /**
     * @return list<array{string, string, bool}>
     */
    private function recipientsOf(Invoice $invoice): array
    {
        return array_map(static fn (SendRecipient $recipient): array => [$recipient->address, $recipient->field, $recipient->checked], $this->draft()->for($invoice)->recipients);
    }
}
