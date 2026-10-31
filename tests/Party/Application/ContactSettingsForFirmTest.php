<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Audit\Domain\AuditEntry;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\CreateParty;
use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyFirmSettings;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * A contact's invoicing settings can differ per firm of the organisation; the
 * contact's own defaults are what a firm without settings falls back to.
 */
final class ContactSettingsForFirmTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantContext $context;
    private TenantId $tenant;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em(), $this->context);
        [$this->tenant] = $this->browser->seed(['Tenant A']);
        $this->context->switchTo($this->tenant);
    }

    public function testFallsBackToTheContactDefaults(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');

        self::assertEquals(InvoicingDefaults::standard(), $this->settings()->defaultsFor($party, $firm));

        $own = new InvoicingDefaults(30, Currency::EUR, DocumentLanguage::English, PaymentMethod::Card, true);
        $this->transaction()->run(static fn () => $party->changeInvoicingDefaults($own));

        self::assertEquals($own, $this->settings()->defaultsFor($party, $firm));
    }

    public function testFirmSettingsWinForThatFirmOnly(): void
    {
        $party = $this->party();
        $first = $this->firm('Example Firm', '12345695');
        $second = $this->firm('Other Firm', '12345687');
        $special = new InvoicingDefaults(7, Currency::USD, DocumentLanguage::English, PaymentMethod::Cash, true);

        $this->transaction()->run(fn () => $this->settings()->change($party, $first, $special));
        $this->em()->clear();
        $this->context->switchTo($this->tenant);
        $party = $this->em()->find(Party::class, $party->id()) ?? self::fail();

        self::assertEquals($special, $this->settings()->defaultsFor($party, $this->firms()->get($first->id())));
        self::assertEquals(InvoicingDefaults::standard(), $this->settings()->defaultsFor($party, $this->firms()->get($second->id())));
        self::assertEquals(InvoicingDefaults::standard(), $party->invoicingDefaults());
    }

    public function testChangingTheSettingsAgainUpdatesTheSameRowAndIsAudited(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->transaction()->run(fn () => $this->settings()->change($party, $firm, new InvoicingDefaults(7, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)));
        $this->transaction()->run(fn () => $this->settings()->change($party, $firm, new InvoicingDefaults(21, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)));

        self::assertSame(1, $this->rows(PartyFirmSettings::class));
        self::assertSame(21, $this->settings()->defaultsFor($party, $firm)->dueDays);
        $entries = $this->em()->createQuery('SELECT a FROM '.AuditEntry::class.' a WHERE a.rootClass = :class AND a.rootId = :id AND a.entityClass = :child')
            ->setParameter('class', Party::class)->setParameter('id', $party->id())->setParameter('child', PartyFirmSettings::class)->getResult();
        self::assertCount(2, $entries);
    }

    public function testSettingsEqualToTheContactDefaultsAreNotStored(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');

        $this->transaction()->run(fn () => $this->settings()->change($party, $firm, InvoicingDefaults::standard()));

        self::assertSame(0, $this->rows(PartyFirmSettings::class));
    }

    public function testAnArchivedFirmGetsNoNewSettings(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->transaction()->run(static fn () => $firm->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));

        $this->expectException(\DomainException::class);
        $this->transaction()->run(fn () => $this->settings()->change($party, $firm, new InvoicingDefaults(7, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)));
    }

    public function testAContactFormShowsNoFirmSectionWithOneFirm(): void
    {
        $party = $this->party();
        $this->firm('Example Firm', '12345695');
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-firm-settings]'));
        self::assertSelectorNotExists('input[name^="firms["]');

        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertCount(0, $crawler->filter('[data-firm-settings]'));
    }

    public function testAContactFormShowsOneSectionPerActiveFirm(): void
    {
        $party = $this->party();
        $first = $this->firm('Example Firm', '12345695');
        $second = $this->firm('Other Firm', '12345687');
        $archived = $this->firm('Old Firm', '12345679');
        $this->transaction()->run(static fn () => $archived->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-firm-settings]'));
        self::assertSelectorExists(\sprintf('input[name="firms[%s][dueDays]"][value="14"]', $first->id()));
        self::assertSelectorExists(\sprintf('select[name="firms[%s][currency]"]', $second->id()));
        self::assertSelectorNotExists('[data-firm-settings="'.$archived->id().'"]');
        self::assertSelectorExists('[data-action-bar]');
    }

    public function testTheFormSavesTheSettingsOfOneFirmAndShowsThemAgain(): void
    {
        $party = $this->party();
        $first = $this->firm('Example Firm', '12345695');
        $second = $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $form = $crawler->filter('main form')->form();
        $form['firms['.$first->id().'][dueDays]'] = '45';
        $form['firms['.$first->id().'][currency]'] = 'EUR';
        $form['firms['.$first->id().'][language]'] = 'en';
        $form['firms['.$first->id().'][paymentMethod]'] = 'card';
        $reverse = $form->get('firms['.$first->id().'][reverseCharge]');
        self::assertInstanceOf(ChoiceFormField::class, $reverse);
        $reverse->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->context->switchTo($this->tenant);
        $this->em()->clear();
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        self::assertEquals(new InvoicingDefaults(45, Currency::EUR, DocumentLanguage::English, PaymentMethod::Card, true), $this->settings()->defaultsFor($stored, $this->firms()->get($first->id())));
        self::assertEquals(InvoicingDefaults::standard(), $this->settings()->defaultsFor($stored, $this->firms()->get($second->id())));
        self::assertSame(1, $this->rows(PartyFirmSettings::class));

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        self::assertSelectorExists(\sprintf('input[name="firms[%s][dueDays]"][value="45"]', $first->id()));
        self::assertSame('EUR', $crawler->filter(\sprintf('select[name="firms[%s][currency]"] option[selected]', $first->id()))->attr('value'));
    }

    public function testAWrongDuePeriodIsAFormErrorAndSavesNothing(): void
    {
        $party = $this->party();
        $first = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $form = $crawler->filter('main form')->form(['displayName' => 'Renamed Company']);
        $form['firms['.$first->id().'][dueDays]'] = '400';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists(\sprintf('input[name="firms[%s][dueDays]"][aria-invalid="true"]', $first->id()));
        $this->em()->clear();
        $this->context->switchTo($this->tenant);
        self::assertSame('Example Company', ($this->em()->find(Party::class, $party->id()) ?? self::fail())->displayName());
        self::assertSame(0, $this->rows(PartyFirmSettings::class));
    }

    public function testASubmissionForAFirmOfAnotherOrganisationIsIgnored(): void
    {
        $party = $this->party();
        $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $foreign = $this->browser->fixtures->tenant('Foreign Tenant');
        $this->context->switchTo($foreign);
        $foreignFirm = $this->firm('Foreign Firm', '12345679');
        $this->context->switchTo($this->tenant);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $values = $crawler->filter('main form')->form()->getPhpValues();
        $foreignValues = ['firms' => [$foreignFirm->id() => ['dueDays' => '5', 'currency' => 'CZK', 'language' => 'cs', 'paymentMethod' => 'cash']]];
        $this->client->request('POST', '/contacts/'.$party->id().'/edit', array_replace_recursive($values, $foreignValues));

        self::assertResponseRedirects('/contacts/'.$party->id());
        self::assertSame(0, $this->rows(PartyFirmSettings::class));
    }

    public function testCreatingAContactSavesTheSettingsOfItsFirms(): void
    {
        $first = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/new');
        $form = $crawler->filter('main form')->form([
            'displayName' => 'New Company',
            'registrationNumber' => '12345679',
            'street' => 'Example Street 1',
            'city' => 'Example City',
            'postalCode' => '100 00',
        ]);
        $form['firms['.$first->id().'][dueDays]'] = '60';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->context->switchTo($this->tenant);
        $this->em()->clear();
        $created = $this->em()->createQuery('SELECT p FROM '.Party::class.' p WHERE p.displayName = :name')->setParameter('name', 'New Company')->getSingleResult();
        self::assertInstanceOf(Party::class, $created);
        self::assertSame(60, $this->settings()->defaultsFor($created, $this->firms()->get($first->id()))->dueDays);
    }

    public function testArchivingAFirmWithSettingsKeepsThem(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $special = new InvoicingDefaults(7, Currency::USD, DocumentLanguage::English, PaymentMethod::Cash, true);
        $this->transaction()->run(fn () => $this->settings()->change($party, $firm, $special));

        $this->transaction()->run(static fn () => $firm->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));
        $this->em()->clear();
        $this->context->switchTo($this->tenant);

        self::assertSame(1, $this->rows(PartyFirmSettings::class));
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        self::assertEquals($special, $this->settings()->defaultsFor($stored, $this->firms()->get($firm->id())));
    }

    public function testAFirmUsedBySettingsCannotBeDeleted(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->transaction()->run(fn () => $this->settings()->change($party, $firm, new InvoicingDefaults(7, Currency::USD, DocumentLanguage::English, PaymentMethod::Cash, true)));
        $connection = $this->em()->getConnection();
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            // SQLite enforces foreign keys only when asked, and only outside a transaction.
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }

        try {
            $this->expectException(ForeignKeyConstraintViolationException::class);
            $connection->executeStatement('DELETE FROM legal_entity WHERE id = ?', [$firm->id()]);
        } finally {
            if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $connection->executeStatement('PRAGMA foreign_keys = OFF');
            }
        }
    }

    public function testNoServiceRemovesAFirm(): void
    {
        self::assertFalse(method_exists(LegalEntities::class, 'remove'));
        self::assertFalse(method_exists(LegalEntities::class, 'delete'));
    }

    private function party(): Party
    {
        return self::getContainer()->get(CreateParty::class)->create(PartyKind::Company, 'Example Company', null, VatId::fromString('CZ12345695'), '', '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
    }

    private function firm(string $name, string $number): LegalEntity
    {
        $firm = new LegalEntity($name, new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
        $this->transaction()->run(fn () => $this->firms()->add($firm));

        return $firm;
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(e.id) FROM '.$class.' e')->getSingleScalarResult();
    }

    private function settings(): ContactSettingsForFirm
    {
        return self::getContainer()->get(ContactSettingsForFirm::class);
    }

    private function firms(): LegalEntities
    {
        return self::getContainer()->get(LegalEntities::class);
    }

    private function transaction(): TransactionalWork
    {
        return self::getContainer()->get(TransactionalWork::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
