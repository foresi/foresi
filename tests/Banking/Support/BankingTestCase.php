<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * The test firm with its account, a clock on 20 November 2026 and the banking
 * services, whichever modules pair the movements. A test that pays invoices
 * extends InvoiceBankingTestCase in Integration/Invoicing.
 */
abstract class BankingTestCase extends KernelTestCase
{
    use BankingFixtures;

    protected const string IBAN = 'CZ6508000000192000145399';

    protected EntityManagerInterface $em;
    protected LegalEntity $firm;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->firm = new LegalEntity(
            'Example Firm s.r.o.',
            new RegistrationNumber('CZ', '12345695'),
            VatId::fromString('CZ12345695'),
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
            VatStatus::Registered,
            'Example register, entry C 1',
        );
        $this->em->persist($this->firm);
        FirmAccounts::add($this->em, self::IBAN);
        $this->em->flush();
        $this->setUpBanking();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }
}
