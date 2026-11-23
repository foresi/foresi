<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Invoicing\Application\DeliverInvoiceSend;
use Foresi\Invoicing\Application\SendDraft;
use Foresi\Invoicing\Application\SendRecipient;
use Foresi\Invoicing\Contract\DocumentParty;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Tests\Invoicing\Application\SendingTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendDraftTest extends SendingTestCase
{
    public function testRecipientsComeFromTheContact(): void
    {
        $invoice = $this->issue->issue($this->input());

        self::assertSame([['billing@example.com', 'to', true]], $this->recipientsOf($invoice));
        self::assertFalse($this->draft()->for($invoice)->fromDocument);
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

        self::assertStringStartsWith('Invoice 20260001 from ', $draft->subject);
        // Filled in and readable, all but the link, which only the sent mail has.
        self::assertStringStartsWith("Hello,\n\nPlease find your invoice 20260001 below", $draft->body);
        self::assertStringContainsString('- Due date: **15 Nov 2026**', $draft->body);
        self::assertStringContainsString("\n{link}\n", $draft->body);
        self::assertSame(DocumentLanguage::English, $draft->language);
    }

    public function testACorrectionGoesOutWithItsOwnTemplateNamingTheOriginal(): void
    {
        $original = $this->issue->issue($this->input());
        $credit = $this->correctionOf($original, '-400.00');

        $draft = $this->draft()->for($credit);

        self::assertSame('Opravný daňový doklad '.$credit->number(), $draft->subject);
        self::assertStringContainsString('posílám Vám opravný daňový doklad č. '.$credit->number().', který opravuje doklad č. '.$original->number().'.', $draft->body);
        self::assertStringContainsString("\n{link}\n", $draft->body);
    }

    public function testTheFirmsOwnTemplateIsUsed(): void
    {
        $templates = self::getContainer()->get(\Foresi\Invoicing\Application\MailTemplates::class);
        $templates->save(\Foresi\Invoicing\Domain\MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Doklad {number}', "Dobrý den,\n{customer_name}\n{link}");
        $this->em->flush();
        $invoice = $this->issue->issue($this->input());

        $draft = $this->draft()->for($invoice);

        self::assertSame('Doklad 20260001', $draft->subject);
        self::assertSame("Dobrý den,\nExample Customer a.s.\n{link}", $draft->body);
    }

    public function testTheSubjectIsOneLineWithoutSpacesAround(): void
    {
        $templates = self::getContainer()->get(\Foresi\Invoicing\Application\MailTemplates::class);
        $templates->save(\Foresi\Invoicing\Domain\MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Doklad {number} {order_number}', '{link}');
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

        // The person signs, the firm stands under the name.
        self::assertStringEndsWith("\nExample Sender\nExample Firm s.r.o.", $body);
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
