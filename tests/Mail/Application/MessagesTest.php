<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AuthenticationResult;
use Foresi\Mail\Contract\MailAttachment;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\SenderCheck;
use Foresi\Mail\Contract\UnknownMailMessage;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Support\Mail\MailKit;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Other modules read a stored message through Messages: the row as a view, and
 * everything else from the raw file, read anew and checked against its hash.
 */
final class MessagesTest extends KernelTestCase
{
    private const string MIME = __DIR__.'/../../Fixtures/Mail/mime/';

    private MailKit $kit;
    private Messages $messages;

    protected function setUp(): void
    {
        self::bootKernel();
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $container = self::getContainer();
        $this->kit = MailKit::seed($container->get(EntityManagerInterface::class), $container->get(AddressIssuer::class));
        $this->messages = $container->get(Messages::class);
    }

    protected function tearDown(): void
    {
        RecordingPurpose::reset();
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testFindGivesTheStoredMessageAsAView(): void
    {
        $raw = MailKit::mail($this->kit->address, headers: ['References' => '<first@mail.example.com> <second@mail.example.com>']);
        $id = $this->stored($raw, InboundChannel::Webhook);

        $view = $this->messages->find($id);

        self::assertEquals(new MailMessageView(
            $id, $this->kit->addressId, RecordingPurpose::KEY, null, 'webhook', MailKit::MESSAGE_ID, null, ['first@mail.example.com', 'second@mail.example.com'],
            'info@bank.example.com', 'Payment notification', new \DateTimeImmutable('2026-11-20 09:00:00', new \DateTimeZone('UTC')), new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('UTC')),
            'unknown', hash('sha256', $raw), \strlen($raw),
        ), $view);
        self::assertNull($this->messages->find(Id::generate()));
    }

    public function testRawAndMailReadTheStoredBytes(): void
    {
        $raw = MailKit::mail($this->kit->address);
        $id = $this->stored($raw);

        self::assertSame($raw, $this->messages->raw($id));
        self::assertSame('Payment notification', $this->messages->mail($id)->subject());
    }

    public function testAChangedFileIsRefused(): void
    {
        $id = $this->stored(MailKit::mail($this->kit->address));
        $directory = self::getContainer()->getParameter('app.inbound_mail_dir');
        $key = self::getContainer()->get(Connection::class)->fetchOne('SELECT raw_key FROM mail_message WHERE id = ?', [$id]);
        self::assertIsString($key);
        file_put_contents($directory.'/'.$key, "From: someone@example.com\r\n\r\nChanged.\r\n");

        try {
            $this->messages->raw($id);
            self::fail('A changed file is not the message that came.');
        } catch (\RuntimeException $changed) {
            self::assertNotInstanceOf(UnknownMailMessage::class, $changed);
        }
        self::assertNull($this->messages->text($id));
        self::assertSame([], $this->messages->attachments($id));
        self::assertSame([], $this->messages->authenticationResults($id));
    }

    public function testAnUnknownMessageIsRefused(): void
    {
        $this->expectException(UnknownMailMessage::class);

        $this->messages->raw(Id::generate());
    }

    public function testTextIsThePlainBody(): void
    {
        $id = $this->stored(MailKit::mail($this->kit->address, body: "Amount: 150.00 CZK\r\n"));

        self::assertSame("Amount: 150.00 CZK\r\n", $this->messages->text($id));
    }

    public function testTextIsTheHtmlBodyAsTextWhenThereIsNoPlainOne(): void
    {
        $id = $this->stored($this->fixture('html-only-qp-utf8.eml'));

        // A no-break space counts as a space.
        self::assertSame("Amount: 1 234,50 CZK\nNote: naïve café – déjà vu", $this->messages->text($id));
    }

    public function testAttachmentsAreListedAndRead(): void
    {
        $id = $this->stored($this->fixture('attachment.eml'));
        $pdf = base64_decode('JVBERi0xLjQKU1lOVEhFVElDIEFUVEFDSE1FTlQKJSVFT0YK', true);

        self::assertEquals([new MailAttachment(0, 'Oznameni.pdf', 'application/pdf', \strlen($pdf))], $this->messages->attachments($id));
        $content = $this->messages->attachment($id, 0);
        self::assertSame(['Oznameni.pdf', 'application/pdf', $pdf], [$content->name, $content->contentType, $content->bytes]);

        $this->expectException(UnknownMailMessage::class);
        $this->messages->attachment($id, 1);
    }

    public function testAMessageWithoutAttachmentsHasNone(): void
    {
        self::assertSame([], $this->messages->attachments($this->stored(MailKit::mail($this->kit->address))));
    }

    public function testAuthenticationResultsAreParsedTopmostFirstAndTrustedForTheChannel(): void
    {
        $raw = $this->fixture('auth-results.eml');
        $byImap = $this->stored($raw, InboundChannel::Imap);

        $results = $this->messages->authenticationResults($byImap);

        self::assertSame(['mx.example.com', 'relay.example.net'], array_map(static fn (AuthenticationResult $result): string => $result->authservId, $results));
        // The relay is a receiving server of the webhook only.
        self::assertSame([true, false], array_map(static fn (AuthenticationResult $result): bool => $result->trusted, $results));
        self::assertSame(['dkim', 'dmarc'], array_column($results[0]->results, 'method'));
        self::assertSame(['pass', 'pass'], array_column($results[0]->results, 'result'));
    }

    public function testTheVerdictIsJudgedAgainstTheDomainsGivenOrTheSendersOwn(): void
    {
        $id = $this->stored($this->fixture('../auth/dmarc-pass.eml'), InboundChannel::Imap);

        self::assertEquals(new SenderCheck(SenderCheck::VERIFIED, 'dmarc'), $this->messages->verdict($id, []));
        self::assertEquals(new SenderCheck(SenderCheck::VERIFIED, 'dmarc'), $this->messages->verdict($id, ['kb.cz']));
        self::assertEquals(new SenderCheck(SenderCheck::FAILED, 'sender'), $this->messages->verdict($id, ['bank.example.com']));
        // The generic verdict was stored on arrival.
        self::assertSame('verified', $this->messages->find($id)?->verdict);
    }

    /**
     * Stores the mail for the kit's address and gives its id.
     */
    private function stored(string $raw, InboundChannel $channel = InboundChannel::Imap): string
    {
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveMail::class)->receive($raw, $channel));
        $id = self::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM mail_message WHERE raw_sha256 = ?', [hash('sha256', $raw)]);
        self::assertIsString($id);

        return $id;
    }

    private function fixture(string $name): string
    {
        $raw = file_get_contents(self::MIME.$name);
        self::assertIsString($raw);

        return str_replace('bank+token@example.com', $this->kit->address, $raw);
    }
}
