<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Idoklad\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Idoklad\Application\Import\DocumentsStage;
use Foresi\Idoklad\Domain\IdokladPull;
use Foresi\Idoklad\Domain\IdokladRecord;
use Foresi\Idoklad\Domain\IdokladResource;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issued invoices and proformas of a snapshot as built after the list models
 * of the iDoklad API v3 documentation. Importing them needs the invoicing
 * rules of the firm's country, so those tests are in Integration/Country.
 */
final class IdokladDocumentsImportTest extends KernelTestCase
{
    private const int CZECHIA = 2;
    private const int CZK = 1;
    private const int EUR = 2;
    private const int TRANSFER = 1;
    private const int CASH_ON_DELIVERY = 2;
    private const int SERVICES = 7;
    private const int CUSTOMER = 501;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->em->persist(new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->record(IdokladResource::Countries, self::CZECHIA, ['Id' => self::CZECHIA, 'Code' => 'CZE', 'Name' => 'Česká republika', 'NameEnglish' => 'Czech Republic', 'CurrencyId' => self::CZK]);
        $this->record(IdokladResource::Currencies, self::CZK, ['Id' => self::CZK, 'Code' => 'CZK', 'Symbol' => 'Kč']);
        $this->record(IdokladResource::Currencies, self::EUR, ['Id' => self::EUR, 'Code' => 'EUR', 'Symbol' => '€']);
        $this->record(IdokladResource::PaymentOptions, self::TRANSFER, ['Id' => self::TRANSFER, 'Code' => 'B', 'Name' => 'Převodem', 'IsDefault' => true]);
        $this->record(IdokladResource::PaymentOptions, self::CASH_ON_DELIVERY, ['Id' => self::CASH_ON_DELIVERY, 'Code' => 'D', 'Name' => 'Dobírka', 'IsDefault' => false]);
        $this->record(IdokladResource::PaymentOptions, 3, ['Id' => 3, 'Code' => 'H', 'Name' => 'Hotově', 'IsDefault' => false]);
        $this->record(IdokladResource::ConstantSymbols, self::SERVICES, ['Id' => self::SERVICES, 'Code' => '0308', 'Name' => 'Služby', 'CountryId' => self::CZECHIA]);
        $this->record(IdokladResource::Contacts, self::CUSTOMER, ['Id' => self::CUSTOMER, 'CompanyName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787', 'VatIdentificationNumber' => 'CZ23456787', 'Email' => 'billing@example.com', 'Street' => 'Example Street 9', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => self::CZECHIA]);
        $this->record(IdokladResource::NumericSequences, 1, ['Id' => 1, 'DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 9, 'IsDefault' => true]);
        $this->record(IdokladResource::NumericSequences, 2, ['Id' => 2, 'DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2019, 'LastNumber' => 3, 'IsDefault' => true]);
        $this->record(IdokladResource::NumericSequences, 3, ['Id' => 3, 'DocumentType' => 1, 'Name' => 'Zálohy', 'NumberFormat' => 'ZRRRRNNNN', 'Year' => 2026, 'LastNumber' => 2, 'IsDefault' => true]);
        $pull = new IdokladPull(new \DateTimeImmutable('2026-11-06 09:00:00'));
        $pull->finish(new \DateTimeImmutable('2026-11-06 09:05:00'), 3);
        $this->em->persist($pull);
        $this->em->flush();
    }

    public function testTheStageRunsAfterTheSeriesAndBeforeThePayments(): void
    {
        self::assertSame(300, self::getContainer()->get(DocumentsStage::class)->order());
    }

    /**
     * @param array<mixed> $payload
     */
    private function record(IdokladResource $resource, int $id, array $payload): void
    {
        $this->em->persist(new IdokladRecord($resource, $id, $payload, new \DateTimeImmutable('2026-11-06 09:01:00')));
        $this->em->flush();
    }
}
