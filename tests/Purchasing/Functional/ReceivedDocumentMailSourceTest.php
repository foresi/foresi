<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\User;
use Foresi\Mail\Contract\Messages;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Purchasing\Domain\DocumentSource;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentContent;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Purchasing\Support\StoredMessages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Where a draft that came by mail says it came from: the sender, the subject and the date on its
 * page, the sender's own text escaped, and the Source column of the list.
 */
final class ReceivedDocumentMailSourceTest extends WebTestCase
{
    private const string MAIL = '5f0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b11';

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set(Messages::class, new StoredMessages(self::MAIL, 'Pat Supplier <pat@supplier.example.com>', 'Invoice <b>11/2026</b>', new \DateTimeImmutable('2026-11-20 09:00:00 UTC')));
        $browser = new SignedInBrowser($this->client, $this->em());
        $this->user = $browser->seed();
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, '');
        $this->em()->persist($firm);
        $this->em()->flush();
        $browser->signIn();
    }

    public function testDetailShowsFromEmailLine(): void
    {
        $id = $this->document(DocumentSource::Mail, self::MAIL);

        $crawler = $this->client->request('GET', '/purchasing/'.$id);

        self::assertResponseIsSuccessful();
        $line = $crawler->filter('[data-purchasing-mail-source]');
        self::assertCount(1, $line);
        self::assertSame('From e-mail: Pat Supplier <pat@supplier.example.com>, Invoice <b>11/2026</b>, 2026-11-20 10:00', preg_replace('/\s+/', ' ', trim($line->text())));
        // The subject is the sender's text: it is shown as text, never as markup.
        self::assertStringContainsString('Invoice &lt;b&gt;11/2026&lt;/b&gt;', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-purchasing-mail-source] b'));
    }

    public function testNoLineForADocumentThatDidNotComeByMail(): void
    {
        $id = $this->document(DocumentSource::Upload, null);

        $crawler = $this->client->request('GET', '/purchasing/'.$id);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-purchasing-mail-source]'));
    }

    public function testTheListNamesTheMailSource(): void
    {
        $this->document(DocumentSource::Mail, self::MAIL);

        $crawler = $this->client->request('GET', '/purchasing');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('E-mail', $crawler->filter('main')->text());
    }

    private function document(DocumentSource $source, ?string $mail): string
    {
        $now = new \DateTimeImmutable('2026-11-20 09:05:00');
        $document = new ReceivedDocument(ReceivedDocumentContent::blank($now), $this->user->id(), $now, $source, $mail);
        $this->em()->persist($document);
        $this->em()->flush();

        return $document->id();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
