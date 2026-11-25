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
use Foresi\Purchasing\Domain\MailIntake;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Purchasing\Support\StoredMessages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Received documents: the person's own capture address, made, renamed, replaced and revoked
 * from the page header; the forwarding steps; and the recent e-mails with the sender's text escaped.
 */
final class ReceivedDocumentsSettingsTest extends WebTestCase
{
    private const string URL = '/settings/received-documents';
    private const string MAIL = '5f0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b11';

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
