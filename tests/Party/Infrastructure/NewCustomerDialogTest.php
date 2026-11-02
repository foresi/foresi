<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Party\Application\PartyDetails;
use App\Party\Domain\Party;
use App\Party\Domain\PartyRole;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The new contact as a dialog over the invoice form: a short form whose result is
 * the new contact's id, handed to the page as an event.
 */
final class NewCustomerDialogTest extends WebTestCase
{
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1'];

    private KernelBrowser $client;
    private TenantContext $context;
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
        [$this->tenant] = $browser->seed(['Tenant A']);
        $browser->signIn();
    }

    public function testTheDialogIsAShortFormWithTheWayToTheFullOne(): void
    {
        $crawler = $this->open();

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('<dialog', ltrim((string) $this->client->getResponse()->getContent()));
        self::assertSame('New customer', $crawler->filter('dialog h2')->text());
        self::assertStringContainsString('max-w-[560px]', (string) $crawler->filter('dialog')->attr('class'));
        $form = $crawler->filter('form#contact-quick-form');
        self::assertSame('/contacts/new', $form->attr('action'));
        // The registry lookup first, then the four things an invoice needs at once.
        $ids = $form->filter('input:not([type="hidden"])')->each(static fn (Crawler $input): string => (string) $input->attr('id'));
        self::assertSame(['lookup', 'displayName', 'registrationNumber', 'email', 'street', 'city', 'postalCode'], $ids);
        self::assertSame('customer', $form->filter('input[type="hidden"][name="roles[]"]')->attr('value'));
        // The address the lookup fills waits behind its own disclosure.
        self::assertNull($form->filter('details[data-quick-address]')->attr('open'));
        self::assertCount(1, $form->filter('details[data-quick-address] #street[required]'));
        $full = $crawler->filter('a[href="/contacts/new"][target="_blank"]');
        self::assertSame('dialog#discard', $full->attr('data-action'));
        self::assertSame('Create customer', trim($crawler->filter('footer button[type="submit"][form="contact-quick-form"]')->text()));
    }

    public function testCreatingStoresTheCustomerAndHandsItsIdToThePage(): void
    {
        $this->post(['displayName' => 'Example Customer s.r.o.', 'registrationNumber' => '12345687', 'email' => 'billing@example.com', 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '100 00']);

        self::assertResponseIsSuccessful();
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($result);
        self::assertSame('contact:created', $result['event']);
        self::assertIsArray($result['detail']);
        $id = $result['detail']['id'];
        self::assertIsString($id);
        self::assertSame('The contact is saved.', $result['message']);

        $party = $this->stored($id);
        self::assertSame('Example Customer s.r.o.', $party->displayName());
        self::assertSame('12345687', $party->registrationNumber()?->value);
        self::assertSame([PartyRole::Customer], $party->roles());
        $this->context->switchTo($this->tenant);
        $details = self::getContainer()->get(PartyDetails::class);
        self::assertSame('billing@example.com', $details->generalEmail($party)?->address());
        self::assertSame('Example City', $details->billingAddress($party)?->address()->city);
        $this->context->reset();
        // Nothing waits for another page: the invoice form picked the customer itself.
        $this->client->request('GET', '/contacts');
        self::assertSelectorNotExists('[data-controller="contact-created"]');
        self::assertSelectorTextNotContains('main', 'The contact is saved.');
    }

    public function testAMissingAddressOpensItWithTheErrorsAndKeepsWhatWasTyped(): void
    {
        $this->post(['displayName' => 'Typed By Hand s.r.o.', 'email' => 'billing@example.com']);

        self::assertResponseStatusCodeSame(422);
        $crawler = new Crawler((string) $this->client->getResponse()->getContent());
        self::assertNotNull($crawler->filter('details[data-quick-address]')->attr('open'));
        self::assertSame('true', $crawler->filter('#street')->attr('aria-invalid'));
        self::assertSame('Typed By Hand s.r.o.', $crawler->filter('#displayName')->attr('value'));
        // The summary leads to each field; the first field to fix takes the focus.
        self::assertSame('#street', $crawler->filter('[data-error-summary] a')->first()->attr('href'));
        self::assertNull($crawler->filter('[data-error-summary]')->attr('autofocus'));
        self::assertSame(0, $this->partyCount());
    }

    public function testTheFullFormStaysThePage(): void
    {
        $crawler = $this->client->request('GET', '/contacts/new');

        self::assertSelectorTextContains('h1', 'New contact');
        self::assertCount(1, $crawler->filter('main form #phone'));
        self::assertCount(0, $crawler->filter('dialog#dialog-page'));
    }

    private function open(): Crawler
    {
        $this->client->request('GET', '/contacts/new', server: self::DIALOG);

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * @param array<string, string> $typed
     */
    private function post(array $typed): void
    {
        $form = $this->open()->filter('form#contact-quick-form')->form();
        $values = $form->getPhpValues();
        foreach ($typed as $name => $value) {
            $values[$name] = $value;
        }
        $this->client->request('POST', '/contacts/new', $values, server: self::DIALOG);
    }

    private function stored(string $id): Party
    {
        $this->context->switchTo($this->tenant);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $party = $em->find(Party::class, $id);
        $this->context->reset();
        self::assertInstanceOf(Party::class, $party);

        return $party;
    }

    private function partyCount(): int
    {
        $this->context->switchTo($this->tenant);
        $count = (int) self::getContainer()->get(EntityManagerInterface::class)->createQuery('SELECT COUNT(p.id) FROM '.Party::class.' p')->getSingleScalarResult();
        $this->context->reset();

        return $count;
    }
}
