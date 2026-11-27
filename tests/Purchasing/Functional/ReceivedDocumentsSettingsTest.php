<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressUnavailable;
use Foresi\Mail\Contract\IssuedAddress;
use Foresi\Mail\Contract\Messages;
use Foresi\Purchasing\Application\Mail\ReceivedDocumentsPurpose;
use Foresi\Purchasing\Application\View\CaptureMailLists;
use Foresi\Purchasing\Domain\MailIntake;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Purchasing\Support\StoredMessages;
use Foresi\Tests\Ui\Support\ListPageAssertions;
use Foresi\Ui\Slot\SlotRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Settings → Received documents: the person's own capture address, made, renamed, replaced and revoked
 * from the page header; the forwarding steps; the recent e-mails with the sender's text escaped; and the
 * sections other modules add, for the owners and members only.
 */
final class ReceivedDocumentsSettingsTest extends WebTestCase
{
    use ListPageAssertions;

    private const string URL = '/settings/received-documents';
    private const string MAIL = '5f0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b11';
    private const string OTHER_PERSON = '7a1c2d3e-4f50-4a6b-8c7d-9e0f1a2b3c4d';

    private KernelBrowser $client;
    private User $user;

    public function testSettingsShowsCreateWithoutAddress(): void
    {
        $this->signIn(Role::Member);

        $page = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSame('Received documents', trim($page->filter('h1')->first()->text()));
        self::assertCount(1, $page->filter('a[data-capture-create][data-dialog][href="/settings/received-documents/capture-address/new"]'));
        self::assertCount(1, $page->filter('[data-capture-none]'));
        self::assertCount(0, $page->filter('[data-capture-value]'));
        self::assertCount(1, $page->filter('[data-capture-recent-empty]'));
        self::assertGreaterThan(0, $page->filter('nav a[href="'.self::URL.'"][aria-current="page"]')->count());
    }

    public function testSettingsShowsAddressAndInstructions(): void
    {
        $this->signIn(Role::Member);
        $address = $this->create('Supplier invoices');

        $page = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame($address, $page->filter('[data-capture-value]')->attr('value'));
        self::assertStringContainsString('Supplier invoices', $page->filter('[data-capture-address]')->text());
        self::assertCount(0, $page->filter('[data-capture-create]'), 'One address per person.');
        self::assertCount(1, $page->filter('[data-capture-rename][data-dialog]'));
        self::assertCount(1, $page->filter('form[data-capture-replace][data-confirm-title]'));
        self::assertCount(1, $page->filter('form[data-capture-revoke][data-confirm-title]'));
        foreach (['gmail', 'outlook', 'apple'] as $client) {
            self::assertCount(1, $page->filter('details[data-capture-forward="'.$client.'"]'));
        }
    }

    public function testRenameReplaceAndRevoke(): void
    {
        $this->signIn(Role::Member);
        $old = $this->create('Supplier invoices');
        $page = $this->client->request('GET', self::URL);
        $renameUrl = (string) $page->filter('[data-capture-rename]')->attr('href');

        $form = $this->client->request('GET', $renameUrl)->filter('#capture-address-form')->form(['label' => 'Receipts']);
        $this->client->submit($form);
        self::assertResponseRedirects(self::URL, 303);
        $page = $this->client->request('GET', self::URL);
        self::assertStringContainsString('Receipts', $page->filter('[data-capture-address]')->text());

        $this->client->submit($page->filter('form[data-capture-replace]')->form());
        self::assertResponseRedirects(self::URL, 303);
        $page = $this->client->request('GET', self::URL);
        $new = (string) $page->filter('[data-capture-value]')->attr('value');
        self::assertNotSame($old, $new, 'Replace makes a new address.');
        self::assertStringContainsString('Receipts', $page->filter('[data-capture-address]')->text(), 'With the same label.');

        $this->client->submit($page->filter('form[data-capture-revoke]')->form());
        self::assertResponseRedirects(self::URL, 303);
        $page = $this->client->request('GET', self::URL);
        self::assertCount(0, $page->filter('[data-capture-value]'));
        self::assertCount(1, $page->filter('[data-capture-create]'));
    }

    public function testAnotherPersonsAddressIsNotFound(): void
    {
        $this->signIn(Role::Member);
        $this->create('Supplier invoices');
        $token = (string) $this->client->request('GET', self::URL)->filter('form[data-capture-replace] input[name="_token"]')->attr('value');
        $issuer = self::getContainer()->get(AddressIssuer::class);
        $other = $issuer->issue(ReceivedDocumentsPurpose::KEY, self::OTHER_PERSON, 'Not yours');
        $base = '/settings/received-documents/capture-address/'.$other->id;

        $this->client->request('GET', $base.'/rename');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $base.'/rename', ['label' => 'Taken', '_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $base.'/replace', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $base.'/revoke', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($other->address, $issuer->reveal($other->id), 'The other address is as it was.');
    }

    public function testAnEmptyLabelIsRefusedInTheDialog(): void
    {
        $this->signIn(Role::Member);
        $form = $this->client->request('GET', '/settings/received-documents/capture-address/new')->filter('#capture-address-form')->form(['label' => '  ']);

        $page = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Say what the address is for.', $page->filter('#capture-address-form')->text());
    }

    public function testRecentEmailsShowNothingAccepted(): void
    {
        $this->signIn(Role::Member);
        self::getContainer()->set(Messages::class, new StoredMessages(self::MAIL, 'Pat <pat@supplier.example.com>', 'Invoice <b>11/2026</b>', new \DateTimeImmutable('2026-11-20 09:00:00 UTC')));
        $intake = new MailIntake(self::MAIL, $this->user->id(), '6f0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b12', 'unknown', new \DateTimeImmutable('2026-11-20 09:01:00 UTC'));
        $intake->finish(0, [['name' => '<i>notes</i>.txt', 'reason' => 'unsupported_type'], ['name' => 'logo.png', 'reason' => 'inline_image']], new \DateTimeImmutable('2026-11-20 09:02:00 UTC'));
        $this->em()->persist($intake);
        $this->em()->flush();

        $page = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $recent = $page->filter('[data-capture-recent]');
        $text = preg_replace('/\s+/', ' ', $recent->text());
        self::assertStringContainsString('No attachment we can read', (string) $text);
        self::assertStringContainsString('Invoice <b>11/2026</b>', (string) $text);
        self::assertStringContainsString('<i>notes</i>.txt (not a document); logo.png (image in the mail)', (string) $text);
        self::assertCount(0, $recent->filter('b, i'), 'The sender\'s text is shown as text, never as markup.');
    }

    public function testTheRecentEmailsAreAListWithViews(): void
    {
        $this->signIn(Role::Member);
        self::getContainer()->set(Messages::class, new StoredMessages(self::MAIL, 'Pat <pat@supplier.example.com>', 'Invoice 11/2026', new \DateTimeImmutable('2026-11-20 09:00:00 UTC')));
        $intake = new MailIntake(self::MAIL, $this->user->id(), '6f0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b12', 'unknown', new \DateTimeImmutable('2026-11-20 09:01:00 UTC'));
        $intake->finish(0, [], new \DateTimeImmutable('2026-11-20 09:02:00 UTC'));
        $this->em()->persist($intake);
        $this->em()->flush();
        self::assertListWithViews(self::getContainer()->get(CaptureMailLists::class), 'purchasing.capture_mails', ['from', 'subject', 'outcome'], ['all' => false]);
        $rows = static fn (Crawler $crawler): int => $crawler->filter('[data-capture-recent] tbody tr[data-id]')->count();

        $crawler = $this->client->request('GET', self::URL);
        self::assertSame(['received', 'from', 'subject', 'outcome', 'skipped'], $crawler->filter('[data-capture-recent] colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column')));
        self::assertListTable($crawler, '[data-capture-recent]', ['widths' => '']);
        self::assertSame(1, $rows($crawler));

        self::assertSame(1, $rows($this->client->request('GET', self::URL.'?q=invoice')));
        self::assertSame(0, $rows($this->client->request('GET', self::URL.'?q=zzz')));
        self::assertCount(1, $this->client->getCrawler()->selectLink('Clear the filter'));
        self::assertSame(1, $rows(self::applyBuilder($this->client, self::URL, 'subject', 'Invoice')));
    }

    public function testMarkupInTheSenderSubjectAndFileNamesIsShownAsText(): void
    {
        $this->signIn(Role::Member);
        self::getContainer()->set(Messages::class, new StoredMessages(self::MAIL, '<script>alert(1)</script> <b>Pat</b> <pat@supplier.example.com>', '<img src=x onerror=alert(2)> <i>Invoice</i>', new \DateTimeImmutable('2026-11-20 09:00:00 UTC')));
        $intake = new MailIntake(self::MAIL, $this->user->id(), '6f0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b12', 'unknown', new \DateTimeImmutable('2026-11-20 09:01:00 UTC'));
        $intake->finish(0, [['name' => '<a href="https://evil.example.com">invoice</a>.exe', 'reason' => 'unsupported_type']], new \DateTimeImmutable('2026-11-20 09:02:00 UTC'));
        $this->em()->persist($intake);
        $this->em()->flush();

        $page = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $recent = $page->filter('[data-capture-recent]');
        $text = (string) preg_replace('/\s+/', ' ', $recent->text());
        self::assertStringContainsString('<script>alert(1)</script> <b>Pat</b>', $text);
        self::assertStringContainsString('<img src=x onerror=alert(2)> <i>Invoice</i>', $text);
        self::assertStringContainsString('<a href="https://evil.example.com">invoice</a>.exe', $text);
        self::assertCount(0, $recent->filter('script, b, i, img, a[href="https://evil.example.com"]'), 'Never as markup.');
        self::assertStringNotContainsString('<script>alert(1)', (string) $this->client->getResponse()->getContent());
    }

    public function testTheUnavailableReasonLinksToTheMailDocs(): void
    {
        $this->signIn(Role::Member);
        self::getContainer()->set(AddressIssuer::class, new class implements AddressIssuer {
            public function issue(string $purpose, ?string $ownerUserId, string $label): IssuedAddress
            {
                throw new \LogicException('Not used.');
            }

            public function rotate(string $addressId): IssuedAddress
            {
                throw new \LogicException('Not used.');
            }

            public function reveal(string $addressId): ?string
            {
                return null;
            }

            public function revoke(string $addressId): void
            {
            }

            public function unavailable(string $purpose): AddressUnavailable
            {
                return AddressUnavailable::NotConfigured;
            }

            public function masked(string $text): string
            {
                return $text;
            }
        });

        $page = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $alert = $page->filter('[data-capture-unavailable]');
        self::assertStringContainsString('Inbound mail is not set up on this server', $alert->text());
        self::assertSame('https://github.com/foresi/foresi/blob/main/docs/mail.md', $alert->filter('a')->attr('href'));
        self::assertCount(0, $page->filter('[data-capture-create]'));
    }

    public function testThePageIsInTheSettingsNavigation(): void
    {
        $this->signIn(Role::Owner);

        $page = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $page->filter('nav a[href="'.self::URL.'"][aria-current="page"]')->count());
        self::assertCount(0, $page->filter('nav a[href="/purchasing"][aria-current="page"]'));
    }

    public function testThePageRendersWithNoSectionProvider(): void
    {
        $this->signIn(Role::Owner);
        self::getContainer()->set(SlotRegistry::class, new SlotRegistry([]));

        $page = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-capture-address]'), 'The capture address is a section of the page itself.');
        self::assertCount(0, $page->filter('[data-purchasing-settings-section]'));
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn(null);

        $this->client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Creates the address through the dialog; the full address as the page shows it.
     */
    private function create(string $label): string
    {
        $form = $this->client->request('GET', '/settings/received-documents/capture-address/new')->filter('#capture-address-form')->form(['label' => $label]);
        $this->client->submit($form);
        self::assertResponseRedirects(self::URL, 303);

        return (string) $this->client->request('GET', self::URL)->filter('[data-capture-value]')->attr('value');
    }

    private function signIn(?Role $role): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, $this->em());
        $this->user = $browser->seed('en', $role);
        $browser->signIn();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
