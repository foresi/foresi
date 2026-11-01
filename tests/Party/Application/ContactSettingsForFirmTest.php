<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Audit\Domain\AuditEntry;
use App\Party\Application\ArchiveParty;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\CreateParty;
use App\Party\Application\LegalEntities;
use App\Party\Application\Parties;
use App\Party\Application\PartyDetails;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\InvoicingEmail;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
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

        $this->transaction()->run(fn () => $this->settings()->changeDefaults($party, $first, $special));
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
        $this->transaction()->run(fn () => $this->settings()->changeDefaults($party, $firm, new InvoicingDefaults(7, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)));
        $this->transaction()->run(fn () => $this->settings()->changeDefaults($party, $firm, new InvoicingDefaults(21, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)));

        self::assertSame(1, $this->rows(PartyFirmSettings::class));
        self::assertSame(21, $this->settings()->defaultsFor($party, $firm)->dueDays);
        $entries = $this->em()->createQuery('SELECT a FROM '.AuditEntry::class.' a WHERE a.rootClass = :class AND a.rootId = :id AND a.entityClass = :child')
            ->setParameter('class', Party::class)->setParameter('id', $party->id())->setParameter('child', PartyFirmSettings::class)->getResult();
        self::assertCount(2, $entries);
    }

    public function testAnOverrideEqualToTheSharedValuesIsKept(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');

        $this->transaction()->run(fn () => $this->settings()->changeDefaults($party, $firm, InvoicingDefaults::standard()));

        self::assertSame(1, $this->rows(PartyFirmSettings::class));
        self::assertTrue($this->settings()->hasOwnDefaults($party, $firm));
    }

    public function testSharedValuesApplyWithoutAnOverride(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $shared = new PartyEmail($party, 'invoices@example.com', EmailPurpose::Invoices);
        $this->transaction()->run(fn () => self::getContainer()->get(Parties::class)->addEmail($shared));

        self::assertSame([PartyRole::Customer], $this->settings()->rolesFor($party, $firm));
        self::assertEquals(InvoicingDefaults::standard(), $this->settings()->defaultsFor($party, $firm));
        self::assertSame([$shared], $this->settings()->emailsFor($party, $firm));
        self::assertFalse($this->settings()->isArchivedFor($party, $firm));
        self::assertSame(0, $this->rows(PartyFirmSettings::class));
    }

    public function testARoleCanDifferByFirm(): void
    {
        $party = $this->party();
        $supplierOf = $this->firm('Example Firm', '12345695');
        $customerOf = $this->firm('Other Firm', '12345687');

        $this->transaction()->run(fn () => $this->settings()->changeRoles($party, $supplierOf, [PartyRole::Supplier]));
        $this->reload();
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();

        self::assertSame([PartyRole::Supplier], $this->settings()->rolesFor($stored, $this->firms()->get($supplierOf->id())));
        self::assertSame([PartyRole::Customer], $this->settings()->rolesFor($stored, $this->firms()->get($customerOf->id())));
        self::assertSame([PartyRole::Customer], $stored->roles());
    }

    public function testAFirmCanHaveNoRoleAtAll(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');

        $this->transaction()->run(fn () => $this->settings()->changeRoles($party, $firm, []));

        self::assertSame([], $this->settings()->rolesFor($party, $firm));
        self::assertTrue($this->settings()->hasOwnRoles($party, $firm));
    }

    public function testBackToSharedRemovesTheOverride(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->transaction()->run(function () use ($party, $firm): void {
            $this->settings()->changeRoles($party, $firm, [PartyRole::Supplier]);
            $this->settings()->changeDefaults($party, $firm, new InvoicingDefaults(7, Currency::USD, DocumentLanguage::English, PaymentMethod::Cash, true));
            $this->settings()->changeEmails($party, $firm, [new InvoicingEmail('firm@example.com', EmailPurpose::Invoices)]);
        });

        $this->transaction()->run(fn () => $this->settings()->changeRoles($party, $firm, null));
        self::assertSame([PartyRole::Customer], $this->settings()->rolesFor($party, $firm));
        self::assertSame(7, $this->settings()->defaultsFor($party, $firm)->dueDays);
        self::assertSame(1, $this->rows(PartyFirmSettings::class));

        $this->transaction()->run(fn () => $this->settings()->changeDefaults($party, $firm, null));
        self::assertEquals(InvoicingDefaults::standard(), $this->settings()->defaultsFor($party, $firm));
        self::assertSame(0, $this->rows(PartyFirmSettings::class), 'A row with nothing left is removed.');

        $this->transaction()->run(fn () => $this->settings()->changeEmails($party, $firm, null));
        self::assertSame([], $this->settings()->emailsFor($party, $firm));
        self::assertSame(0, $this->rows(PartyEmail::class));

        $entries = $this->em()->createQuery('SELECT a FROM '.AuditEntry::class.' a WHERE a.rootClass = :class AND a.rootId = :id AND a.entityClass = :child')
            ->setParameter('class', Party::class)->setParameter('id', $party->id())->setParameter('child', PartyFirmSettings::class)->getResult();
        self::assertCount(3, $entries, 'Created, roles removed and deleted, all under the party.');
    }

    public function testClearingOnePanelAndSettingAnotherInOneSaveKeepsTheRow(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->transaction()->run(fn () => $this->settings()->changeRoles($party, $firm, [PartyRole::Supplier]));

        $this->transaction()->run(function () use ($party, $firm): void {
            $this->settings()->changeRoles($party, $firm, null);
            $this->settings()->changeDefaults($party, $firm, new InvoicingDefaults(7, Currency::USD, DocumentLanguage::English, PaymentMethod::Cash, true));
        });
        $this->reload();
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();

        self::assertSame(1, $this->rows(PartyFirmSettings::class));
        self::assertSame(7, $this->settings()->defaultsFor($stored, $this->firms()->get($firm->id()))->dueDays);
    }

    public function testArchivedForOneFirmStaysActiveForTheOther(): void
    {
        $party = $this->party();
        $archivedFor = $this->firm('Example Firm', '12345695');
        $activeFor = $this->firm('Other Firm', '12345687');

        $this->transaction()->run(fn () => $this->settings()->changeArchived($party, $archivedFor, true));

        self::assertTrue($this->settings()->isArchivedFor($party, $archivedFor));
        self::assertFalse($this->settings()->isArchivedFor($party, $activeFor));
        self::assertFalse($party->isArchived());

        $this->transaction()->run(fn () => $this->settings()->changeArchived($party, $archivedFor, false));

        self::assertFalse($this->settings()->isArchivedFor($party, $archivedFor));
        self::assertSame(0, $this->rows(PartyFirmSettings::class));
    }

    public function testArchivedForAllWinsOverAnyFirm(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->transaction()->run(fn () => $this->settings()->changeRoles($party, $firm, [PartyRole::Supplier]));

        $this->transaction()->run(static fn () => $party->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));

        self::assertTrue($this->settings()->isArchivedFor($party, $firm));
    }

    public function testFirmEmailsReplaceSharedInvoicingEmailsForThatFirm(): void
    {
        $party = $this->party();
        $own = $this->firm('Example Firm', '12345695');
        $other = $this->firm('Other Firm', '12345687');
        $parties = self::getContainer()->get(Parties::class);
        $shared = new PartyEmail($party, 'invoices@example.com', EmailPurpose::Invoices);
        $this->transaction()->run(static fn () => $parties->addEmail($shared));
        $this->transaction()->run(fn () => $parties->addEmail(new PartyEmail($party, 'office@example.com', EmailPurpose::General)));

        $this->transaction()->run(fn () => $this->settings()->changeEmails($party, $own, [
            new InvoicingEmail('firm@example.com', EmailPurpose::Invoices),
            new InvoicingEmail('copy@example.com', EmailPurpose::Copy),
        ]));

        self::assertSame(['copy@example.com', 'firm@example.com'], $this->addresses($this->settings()->emailsFor($party, $own)));
        self::assertSame($own, $this->settings()->emailsFor($party, $own)[0]->legalEntity());
        self::assertSame(['invoices@example.com'], $this->addresses($this->settings()->emailsFor($party, $other)));
        self::assertSame(['invoices@example.com', 'office@example.com'], $this->addresses(self::getContainer()->get(PartyDetails::class)->emails($party)), 'The shared e-mails do not include the firm\'s.');
    }

    public function testSavingTheSameEmailsAgainChangesNothing(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $wanted = [new InvoicingEmail('firm@example.com', EmailPurpose::Invoices)];
        $this->transaction()->run(fn () => $this->settings()->changeEmails($party, $firm, $wanted));
        $id = $this->settings()->emailsFor($party, $firm)[0]->id();

        $this->transaction()->run(fn () => $this->settings()->changeEmails($party, $firm, $wanted));

        self::assertSame($id, $this->settings()->emailsFor($party, $firm)[0]->id());
    }

    public function testAFirmEmailIsNeverTheGeneralOne(): void
    {
        $this->expectException(\DomainException::class);

        new PartyEmail($this->party(), 'firm@example.com', EmailPurpose::General, $this->firm('Example Firm', '12345695'));
    }

    public function testAnArchivedFirmGetsNoOverrideOfAnyKind(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->transaction()->run(static fn () => $firm->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));

        $this->expectException(\DomainException::class);
        $this->transaction()->run(fn () => $this->settings()->changeRoles($party, $firm, [PartyRole::Supplier]));
    }

    public function testAnArchivedFirmGetsNoNewSettings(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->transaction()->run(static fn () => $firm->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));

        $this->expectException(\DomainException::class);
        $this->transaction()->run(fn () => $this->settings()->changeDefaults($party, $firm, new InvoicingDefaults(7, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)));
    }

    public function testWithOneFirmTheFormHasNoScopeAndStaysAsItWas(): void
    {
        $party = $this->party();
        $this->firm('Example Firm', '12345695');
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-firm-panel]'));
        self::assertSelectorNotExists('[name^="firms["]');
        self::assertSelectorExists('input[name="roles[]"]');
        self::assertSelectorExists('textarea[name="privateNote"]');

        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertCount(0, $crawler->filter('[data-firm-panel]'));

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $this->client->submit($crawler->filter('main form')->form(['displayName' => 'Renamed Company']));
        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->reload();
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        self::assertSame('Renamed Company', $stored->displayName());
        self::assertEquals(InvoicingDefaults::standard(), $stored->invoicingDefaults());
        self::assertFalse($stored->isArchived());
    }

    public function testEveryPanelHasAScopeOfAllFirmsAndTheActiveOnes(): void
    {
        $party = $this->party();
        $first = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $archived = $this->firm('Old Firm', '12345679');
        $this->transaction()->run(static fn () => $archived->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');

        self::assertResponseIsSuccessful();
        self::assertSame(['roles', 'invoicing', 'emails', 'archive'], $crawler->filter('[data-firm-panel]')->each(static fn ($panel) => $panel->attr('data-firm-panel')));
        foreach (['roles', 'invoicing', 'emails', 'archive'] as $key) {
            $options = $crawler->filter(\sprintf('[data-firm-panel="%s"] [role="menuitemradio"]', $key));
            self::assertCount(3, $options);
            self::assertStringContainsString('All firms', $options->eq(0)->text());
            self::assertSame('true', $options->eq(0)->attr('aria-checked'));
        }
        self::assertSelectorNotExists('[data-scope="'.$archived->id().'"]');
        self::assertSelectorExists(\sprintf('[data-firm-panel="invoicing"] [data-firm-scope-target="pane"][data-scope="%s"][hidden]', $first->id()));
        self::assertSelectorExists('[data-action-bar]');

        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertSame(['roles', 'invoicing', 'emails'], $crawler->filter('[data-firm-panel]')->each(static fn ($panel) => $panel->attr('data-firm-panel')));
    }

    public function testAFirmWithoutAnOverrideShowsTheSharedValuesReadOnly(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');

        $pane = \sprintf('[data-firm-panel="invoicing"] [data-firm-scope-target="pane"][data-scope="%s"]', $firm->id());
        self::assertSelectorExists($pane.' fieldset[disabled] input[name="firms['.$firm->id().'][dueDays]"][value="14"]');
        self::assertSame('', $crawler->filter(\sprintf('input[name="firms[%s][own][invoicing]"]', $firm->id()))->attr('value'));
        self::assertSelectorExists(\sprintf('[data-firm-panel="roles"] [data-scope="%s"] fieldset[disabled] input[name="firms[%s][roles][]"][value="customer"][checked]', $firm->id(), $firm->id()));
        self::assertStringContainsString('Set for this firm', $crawler->filter($pane)->text());
    }

    public function testAnOverrideIsSavedAndShownAsTheFirmsOwn(): void
    {
        $party = $this->party();
        $first = $this->firm('Example Firm', '12345695');
        $second = $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $this->post($party, [$first->id() => [
            'own' => ['roles' => '1', 'invoicing' => '1', 'emails' => '1', 'archive' => '1'],
            'roles' => ['supplier'],
            'dueDays' => '45', 'currency' => 'EUR', 'language' => 'en', 'paymentMethod' => 'card', 'reverseCharge' => '1',
            'invoiceEmails' => "firm@example.com\nfirm@example.com", 'copyEmails' => 'copy@example.com, other@example.com',
            'archived' => '1',
        ]]);

        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->reload();
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        $firm = $this->firms()->get($first->id());
        self::assertSame([PartyRole::Supplier], $this->settings()->rolesFor($stored, $firm));
        self::assertEquals(new InvoicingDefaults(45, Currency::EUR, DocumentLanguage::English, PaymentMethod::Card, true), $this->settings()->defaultsFor($stored, $firm));
        self::assertSame(['copy@example.com', 'firm@example.com', 'other@example.com'], $this->addresses($this->settings()->emailsFor($stored, $firm)));
        self::assertTrue($this->settings()->isArchivedFor($stored, $firm));
        self::assertFalse($this->settings()->isArchivedFor($stored, $this->firms()->get($second->id())));
        self::assertEquals(InvoicingDefaults::standard(), $stored->invoicingDefaults());
        self::assertSame(1, $this->rows(PartyFirmSettings::class));

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        self::assertSelectorExists(\sprintf('input[name="firms[%s][dueDays]"][value="45"]', $first->id()));
        self::assertSame('EUR', $crawler->filter(\sprintf('select[name="firms[%s][currency]"] option[selected]', $first->id()))->attr('value'));
        self::assertSame('1', $crawler->filter(\sprintf('input[name="firms[%s][own][invoicing]"]', $first->id()))->attr('value'));
        self::assertCount(0, $crawler->filter(\sprintf('[data-firm-panel="invoicing"] [data-scope="%s"] fieldset[disabled]', $first->id())));
        foreach (['roles', 'invoicing', 'emails', 'archive'] as $key) {
            self::assertNull($crawler->filter(\sprintf('[data-firm-panel="%s"] [role="menuitemradio"][data-scope="%s"] [data-firm-scope-target="marker"]', $key, $first->id()))->attr('hidden'));
            self::assertNotNull($crawler->filter(\sprintf('[data-firm-panel="%s"] [role="menuitemradio"][data-scope="%s"] [data-firm-scope-target="marker"]', $key, $second->id()))->attr('hidden'));
        }
    }

    public function testBackToSharedThroughTheFormDeletesTheOverride(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->transaction()->run(function () use ($party, $firm): void {
            $this->settings()->changeRoles($party, $firm, [PartyRole::Supplier]);
            $this->settings()->changeEmails($party, $firm, [new InvoicingEmail('firm@example.com', EmailPurpose::Invoices)]);
        });
        $this->browser->signIn();

        $this->post($party, [$firm->id() => ['own' => ['roles' => '0', 'emails' => '0']]]);

        self::assertResponseRedirects('/contacts/'.$party->id());
        self::assertSame(0, $this->rows(PartyFirmSettings::class));
        self::assertSame(0, $this->rows(PartyEmail::class));
    }

    public function testAPanelThatWasNotSentIsLeftAlone(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->transaction()->run(fn () => $this->settings()->changeRoles($party, $firm, [PartyRole::Supplier]));
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $values = $crawler->filter('main form')->form()->getPhpValues();
        unset($values['firms']);
        $this->client->request('POST', '/contacts/'.$party->id().'/edit', $values);

        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->reload();
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        self::assertSame([PartyRole::Supplier], $this->settings()->rolesFor($stored, $this->firms()->get($firm->id())));
    }

    public function testAStaleFormLeavesAnOverrideItNeverShowedAlone(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $values = $crawler->filter('main form')->form()->getPhpValues();
        self::assertSelectorExists(\sprintf('input[name="firms[%s][own][roles]"][value=""]', $firm->id()));

        $this->context->switchTo($this->tenant);
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        $this->transaction()->run(fn () => $this->settings()->changeRoles($stored, $this->firms()->get($firm->id()), [PartyRole::Supplier]));
        $this->client->request('POST', '/contacts/'.$party->id().'/edit', $values);

        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->reload();
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        self::assertSame([PartyRole::Supplier], $this->settings()->rolesFor($stored, $this->firms()->get($firm->id())));
    }

    public function testAFailedSaveKeepsAConfirmedBackToShared(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->transaction()->run(fn () => $this->settings()->changeRoles($party, $firm, [PartyRole::Supplier]));
        $this->browser->signIn();

        $this->post($party, [$firm->id() => ['own' => ['roles' => '0']]], ['displayName' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists(\sprintf('input[name="firms[%s][own][roles]"][value="0"]', $firm->id()));
    }

    public function testWithOneActiveFirmLeftItsOverridesCanBeSeenAndRemoved(): void
    {
        $party = $this->party();
        $untouched = self::getContainer()->get(CreateParty::class)->create(PartyKind::Company, 'Untouched Company', null, VatId::fromString('CZ12345687'), '', '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $firm = $this->firm('Example Firm', '12345695');
        $other = $this->firm('Other Firm', '12345687');
        $this->transaction()->run(fn () => $this->settings()->changeRoles($party, $firm, [PartyRole::Supplier]));
        $this->transaction()->run(static fn () => $other->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        self::assertCount(4, $crawler->filter('[data-firm-panel]'));
        self::assertCount(2, $crawler->filter('[data-firm-panel="roles"] [role="menuitemradio"]'));
        self::assertNull($crawler->filter(\sprintf('[data-firm-panel="roles"] [role="menuitemradio"][data-scope="%s"] [data-firm-scope-target="marker"]', $firm->id()))->attr('hidden'));

        $crawler = $this->client->request('GET', '/contacts/'.$untouched->id().'/edit');
        self::assertCount(0, $crawler->filter('[data-firm-panel]'));

        $this->post($party, [$firm->id() => ['own' => ['roles' => '0']]]);
        self::assertResponseRedirects('/contacts/'.$party->id());
        self::assertSame(0, $this->rows(PartyFirmSettings::class));
        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        self::assertCount(0, $crawler->filter('[data-firm-panel]'));
    }

    public function testAStaleFormDoesNotRestoreAContactArchivedMeanwhile(): void
    {
        $party = $this->party();
        $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $values = $crawler->filter('main form')->form()->getPhpValues();
        // A browser sends the hidden field behind the unticked checkbox; the crawler drops it.
        $values = array_replace_recursive($values, ['firms' => ['shared' => ['archived' => '']]]);

        $this->context->switchTo($this->tenant);
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        self::getContainer()->get(ArchiveParty::class)->archive([$stored]);
        $this->client->request('POST', '/contacts/'.$party->id().'/edit', $values);

        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->reload();
        self::assertTrue(($this->em()->find(Party::class, $party->id()) ?? self::fail())->isArchived());
    }

    public function testAStaleFormDoesNotArchiveAContactRestoredMeanwhile(): void
    {
        $party = $this->party();
        $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        self::getContainer()->get(ArchiveParty::class)->archive([$party]);
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $values = $crawler->filter('main form')->form()->getPhpValues();
        self::assertSelectorExists('input[type="checkbox"][name="firms[shared][archived]"][checked]');

        $this->context->switchTo($this->tenant);
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        self::getContainer()->get(ArchiveParty::class)->restore([$stored]);
        $this->client->request('POST', '/contacts/'.$party->id().'/edit', $values);

        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->reload();
        self::assertFalse(($this->em()->find(Party::class, $party->id()) ?? self::fail())->isArchived());
    }

    public function testAnEmptyListOfEmailsIsNoOverride(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $this->post($party, [$firm->id() => ['own' => ['emails' => '1'], 'invoiceEmails' => '', 'copyEmails' => '']]);

        self::assertResponseRedirects('/contacts/'.$party->id());
        self::assertSame(0, $this->rows(PartyEmail::class));
    }

    public function testAllFirmsEditsTheSharedValues(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $this->post($party, ['shared' => [
            'dueDays' => '30', 'currency' => 'EUR', 'language' => 'en', 'paymentMethod' => 'card', 'reverseCharge' => '1',
            'invoiceEmails' => 'invoices@example.com', 'copyEmails' => '',
            'archived' => '1', 'archivedBefore' => '',
        ]]);

        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->reload();
        $stored = $this->em()->find(Party::class, $party->id()) ?? self::fail();
        self::assertEquals(new InvoicingDefaults(30, Currency::EUR, DocumentLanguage::English, PaymentMethod::Card, true), $stored->invoicingDefaults());
        self::assertSame(['invoices@example.com'], $this->addresses($this->settings()->sharedEmails($stored)));
        self::assertTrue($stored->isArchived());
        self::assertTrue($this->settings()->isArchivedFor($stored, $this->firms()->get($firm->id())));

        $this->post($stored, ['shared' => ['archived' => '', 'archivedBefore' => '1']]);
        $this->reload();
        self::assertFalse(($this->em()->find(Party::class, $party->id()) ?? self::fail())->isArchived());
    }

    public function testAWrongDuePeriodIsAFormErrorSavesNothingAndStaysOnThatFirm(): void
    {
        $party = $this->party();
        $first = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $this->post($party, [$first->id() => ['own' => ['invoicing' => '1'], 'dueDays' => '400', 'currency' => 'CZK', 'language' => 'cs', 'paymentMethod' => 'cash']], ['displayName' => 'Renamed Company']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists(\sprintf('input[name="firms[%s][dueDays]"][aria-invalid="true"][value="400"]', $first->id()));
        self::assertSelectorExists(\sprintf('[data-firm-panel="invoicing"] [data-firm-scope-target="pane"][data-scope="%s"]:not([hidden])', $first->id()));
        self::assertSelectorExists(\sprintf('input[name="firms[%s][own][invoicing]"][value="1"]', $first->id()));
        $this->reload();
        self::assertSame('Example Company', ($this->em()->find(Party::class, $party->id()) ?? self::fail())->displayName());
        self::assertSame(0, $this->rows(PartyFirmSettings::class));
    }

    public function testAWrongEmailIsAFormError(): void
    {
        $party = $this->party();
        $first = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $this->post($party, [$first->id() => ['own' => ['emails' => '1'], 'invoiceEmails' => 'not an address', 'copyEmails' => '']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists(\sprintf('textarea[name="firms[%s][invoiceEmails]"][aria-invalid="true"]', $first->id()));
    }

    public function testAForgedFirmIsIgnored(): void
    {
        $party = $this->party();
        $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $archived = $this->firm('Old Firm', '12345679');
        $this->transaction()->run(static fn () => $archived->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));
        $foreign = $this->browser->fixtures->tenant('Foreign Tenant');
        $this->context->switchTo($foreign);
        $foreignFirm = $this->firm('Foreign Firm', '12345679');
        $this->context->switchTo($this->tenant);
        $this->browser->signIn();

        $forged = ['own' => ['roles' => '1', 'invoicing' => '1', 'emails' => '1', 'archive' => '1'], 'roles' => ['supplier'], 'dueDays' => '5', 'currency' => 'CZK', 'language' => 'cs', 'paymentMethod' => 'cash', 'invoiceEmails' => 'forged@example.com', 'copyEmails' => '', 'archived' => '1'];
        $this->post($party, [$foreignFirm->id() => $forged, $archived->id() => $forged, 'not-a-firm' => $forged]);

        self::assertResponseRedirects('/contacts/'.$party->id());
        self::assertSame(0, $this->rows(PartyFirmSettings::class));
        self::assertSame(0, $this->rows(PartyEmail::class));
    }

    public function testCreatingAContactSavesTheOverridesOfItsFirms(): void
    {
        $first = $this->firm('Example Firm', '12345695');
        $this->firm('Other Firm', '12345687');
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/contacts/new');
        $values = $crawler->filter('main form')->form([
            'displayName' => 'New Company',
            'registrationNumber' => '12345679',
            'street' => 'Example Street 1',
            'city' => 'Example City',
            'postalCode' => '100 00',
        ])->getPhpValues();
        $this->client->request('POST', '/contacts/new', array_replace_recursive($values, ['firms' => [$first->id() => ['own' => ['invoicing' => '1', 'roles' => '1', 'emails' => '1'], 'dueDays' => '60', 'currency' => 'CZK', 'language' => 'cs', 'paymentMethod' => 'cash', 'roles' => ['partner'], 'invoiceEmails' => 'firm@example.com', 'copyEmails' => '']]]));

        self::assertResponseRedirects();
        $this->reload();
        $created = $this->em()->createQuery('SELECT p FROM '.Party::class.' p WHERE p.displayName = :name')->setParameter('name', 'New Company')->getSingleResult();
        self::assertInstanceOf(Party::class, $created);
        $firm = $this->firms()->get($first->id());
        self::assertSame(60, $this->settings()->defaultsFor($created, $firm)->dueDays);
        self::assertSame([PartyRole::Partner], $this->settings()->rolesFor($created, $firm));
        self::assertSame(['firm@example.com'], $this->addresses($this->settings()->emailsFor($created, $firm)));
    }

    public function testTheAddressBookStaysOnSharedValues(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $this->transaction()->run(function () use ($party, $firm): void {
            $this->settings()->changeRoles($party, $firm, [PartyRole::Supplier]);
            $this->settings()->changeEmails($party, $firm, [new InvoicingEmail('firm@example.com', EmailPurpose::Invoices)]);
            $this->settings()->changeArchived($party, $firm, true);
        });
        $this->browser->signIn();

        $this->client->request('GET', '/contacts');
        self::assertSelectorTextContains('main', 'Example Company');
        self::assertSelectorTextNotContains('main', 'firm@example.com');

        $this->client->request('GET', '/contacts?q=firm@example.com');
        self::assertSelectorTextNotContains('main', 'Example Company');
    }

    public function testArchivingAFirmWithSettingsKeepsThem(): void
    {
        $party = $this->party();
        $firm = $this->firm('Example Firm', '12345695');
        $special = new InvoicingDefaults(7, Currency::USD, DocumentLanguage::English, PaymentMethod::Cash, true);
        $this->transaction()->run(fn () => $this->settings()->changeDefaults($party, $firm, $special));

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
        $this->transaction()->run(fn () => $this->settings()->changeDefaults($party, $firm, new InvoicingDefaults(7, Currency::USD, DocumentLanguage::English, PaymentMethod::Cash, true)));
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

    /**
     * Sends the contact form as a browser would, with the firm values replaced.
     *
     * @param array<string, array<string, mixed>> $firms
     * @param array<string, string>               $fields
     */
    private function post(Party $party, array $firms, array $fields = []): void
    {
        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $values = $crawler->filter('main form')->form()->getPhpValues();
        unset($values['firms']);
        $this->client->request('POST', '/contacts/'.$party->id().'/edit', ['firms' => $firms] + $fields + $values);
    }

    /**
     * @param list<PartyEmail> $emails
     *
     * @return list<string>
     */
    private function addresses(array $emails): array
    {
        $addresses = array_map(static fn (PartyEmail $email): string => $email->address(), $emails);
        sort($addresses);

        return $addresses;
    }

    private function reload(): void
    {
        $this->em()->clear();
        $this->context->switchTo($this->tenant);
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
