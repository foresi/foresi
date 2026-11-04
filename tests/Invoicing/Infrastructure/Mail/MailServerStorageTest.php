<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MailServers;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\MailEncryption;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailServerStorageTest extends KernelTestCase
{
    private const string PASSWORD = 'correct-horse-battery';
    private const string NEW_PASSWORD = 'staple-of-the-month';

    private EntityManagerInterface $em;
    private MailServers $servers;
    private MailSecrets $secrets;
    private LegalEntity $firm;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $container->get(TenantContext::class)->switchTo(TenantId::generate());
        $this->servers = $container->get(MailServers::class);
        $this->secrets = $container->get(MailSecrets::class);

        $this->firm = $this->newFirm('12345695');
        $this->em->flush();
    }

    public function testTheStoredRowHoldsNoPlainSecret(): void
    {
        self::assertTrue($this->secrets->isConfigured());
        $server = FirmMailServer::define($this->firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $this->secrets->seal(self::PASSWORD), 'invoices@example.com', 'Example Firm');
        $this->servers->save($server);
        $this->em->flush();
        $server->change('smtp.example.com', 465, MailEncryption::Tls, 'invoices@example.com', $this->secrets->seal(self::NEW_PASSWORD), 'invoices@example.com', 'Example Firm');
        $this->em->flush();
        $this->em->clear();

        $connection = self::getContainer()->get(Connection::class);
        $rows = $connection->fetchAllAssociative('SELECT * FROM firm_mail_server');
        self::assertCount(1, $rows);
        $audit = $connection->fetchAllAssociative('SELECT * FROM audit_entry');
        self::assertNotSame([], $audit);
        $stored = json_encode([$rows, $audit], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        foreach ([self::PASSWORD, self::NEW_PASSWORD, base64_encode(self::PASSWORD), bin2hex(self::PASSWORD)] as $plain) {
            self::assertStringNotContainsString($plain, $stored);
        }

        $sealed = $rows[0]['secret_sealed'];
        self::assertIsString($sealed);
        self::assertStringNotContainsString($sealed, json_encode($audit, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), 'The sealed value stays out of the audit log too.');
        self::assertStringContainsString('smtp.example.com', json_encode($audit, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), 'The rest of the server is audited.');

        $read = $this->servers->for($this->storedFirm());
        self::assertInstanceOf(FirmMailServer::class, $read);
        self::assertSame(465, $read->port());
        self::assertSame(MailEncryption::Tls, $read->encryption());
        self::assertSame(self::NEW_PASSWORD, $this->secrets->open($read->sealedSecret()));
    }

    public function testAFirmWithoutAServerHasNone(): void
    {
        $this->servers->save(FirmMailServer::define($this->firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'user', $this->secrets->seal(self::PASSWORD), 'invoices@example.com', 'Example Firm'));
        $other = $this->newFirm('87654320');
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->servers->for($this->em->find(LegalEntity::class, $other->id()) ?? self::fail('The other firm is stored.')));
        self::assertInstanceOf(FirmMailServer::class, $this->servers->for($this->storedFirm()));
    }

    private function newFirm(string $registrationNumber): LegalEntity
    {
        $firm = new LegalEntity('Example Firm '.$registrationNumber, new RegistrationNumber('CZ', $registrationNumber), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($firm);

        return $firm;
    }

    private function storedFirm(): LegalEntity
    {
        return $this->em->find(LegalEntity::class, $this->firm->id()) ?? self::fail('The firm is stored.');
    }
}
