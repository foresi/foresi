<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Invoicing\Application\DeliverInvoiceSend;
use App\Invoicing\Application\SendDraft;
use App\Invoicing\Application\SendRecipient;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\PartyEmail;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
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

    public function testTheDocumentsAddressIsOfferedWithoutSpaces(): void
    {
        $this->em->createQuery('DELETE FROM '.PartyEmail::class.' e')->execute();
        $invoice = $this->issue->issue($this->input(overrides: ['customerOnDocument' => $this->typedCustomer(' office@example.com ')]));

        self::assertSame([['office@example.com', 'to', true]], $this->recipientsOf($invoice));
    }

    public function testTheDocumentsAddressTakesThePlaceOfTheSameCopyAddressInAnyCase(): void
    {
        $this->em->createQuery('DELETE FROM '.PartyEmail::class.' e')->execute();
        $this->em->persist(new PartyEmail($this->customer, 'ÚČTY@example.com', EmailPurpose::Copy));
        $this->em->flush();
        $invoice = $this->issue->issue($this->input(overrides: ['customerOnDocument' => $this->typedCustomer('Účty@example.com')]));

        self::assertSame([['Účty@example.com', 'to', true]], $this->recipientsOf($invoice));
    }

    public function testAnAddressTheContactListsTwiceInAnyCaseIsOfferedOnce(): void
    {
        $this->em->persist(new PartyEmail($this->customer, 'ÚČTY@example.com', EmailPurpose::Invoices));
        $this->em->persist(new PartyEmail($this->customer, 'účty@example.com', EmailPurpose::Copy));
        $this->em->flush();
        $invoice = $this->issue->issue($this->input());

        self::assertSame([['billing@example.com', 'to', true], ['ÚČTY@example.com', 'to', true]], $this->recipientsOf($invoice));
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

    public function testACorrectionGoesOutWithItsOwnTemplateNamingTheOriginal(): void
    {
        $original = $this->issue->issue($this->input());
        $credit = $this->correctionOf($original, '-400.00');

        $draft = $this->draft()->for($credit);

        self::assertSame('Opravný daňový doklad '.$credit->number(), $draft->subject);
        self::assertStringStartsWith('Zasílám Vám opravný daňový doklad č. '.$credit->number().' k dokladu č. '.$original->number().' na částku ', $draft->body);
        self::assertStringEndsWith('{link}', $draft->body);
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

    public function testTheSubjectIsOneLineWithoutSpacesAround(): void
    {
        $templates = self::getContainer()->get(\App\Invoicing\Application\MailTemplates::class);
        $templates->save($this->firm, \App\Invoicing\Domain\MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Doklad {number} {order_number}', '{link}');
        $this->em->flush();
        $invoice = $this->issue->issue($this->input(overrides: ['orderNumber' => '']));

        self::assertSame('Doklad 20260001', $this->draft()->for($invoice)->subject);
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

    public function testTheSignedInPersonSignsTheText(): void
    {
        $user = new User('sender@example.com', 'hash', 'Example Sender');
        $this->em->persist($user);
        $this->em->flush();
        $invoice = $this->issue->issue($this->input());
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), 'sender@example.com', 'hash'), 'main', ['ROLE_USER']));

        $body = $this->draft()->for($invoice)->body;

        self::assertStringContainsString("\nExample Sender\n", $body);
        self::assertStringNotContainsString('Example Firm', $body, 'not signed by the firm');
    }

    public function testTheCopyAddressIsTakenWithoutSpaces(): void
    {
        $this->contactEmail(' office@example.com ');
        $invoice = $this->issue->issue($this->input());

        self::assertSame('office@example.com', $this->draft()->for($invoice)->copyAddress);
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

    public function testTheLastSendIsTheLatestMomentWhicheverWasAskedForFirst(): void
    {
        $invoice = $this->issue->issue($this->input());
        [$first, $second, $third] = array_map(function (string $at) use ($invoice): string {
            self::mockTime('2026-11-03 '.$at);

            return $this->sent($invoice)->id();
        }, ['09:00:00', '09:01:00', '09:02:00']);
        // The second goes out last.
        foreach (['10:00' => $first, '11:00' => $third, '12:00' => $second] as $at => $id) {
            self::mockTime('2026-11-03 '.$at.':00');
            $this->worker->run(only: static fn (object $message): bool => $message instanceof DeliverInvoiceSend && $id === $message->sendId);
        }

        self::assertSame('2026-11-03 12:00:00', $this->draft()->for($invoice)->lastSentAt?->format('Y-m-d H:i:s'));
    }

    private function typedCustomer(string $email): DocumentParty
    {
        return new DocumentParty('Example Customer a.s.', '87654320', 'CZ87654320', new Address('Customer Street 2', 'Customer City', '200 00', 'CZ'), '', $email, '', '', null, null);
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
