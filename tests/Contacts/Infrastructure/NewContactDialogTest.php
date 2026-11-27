<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyRole;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The new contact as a dialog over the contact list: the short form with the
 * roles, whose saved contact opens as its page. Without JavaScript the same
 * link is the whole form.
 */
final class NewContactDialogTest extends WebTestCase
{
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1'];
    private const string URL = '/contacts/new?for=contacts';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed();
        $browser->signIn();
    }

    public function testTheContactListOpensTheNewContactInADialog(): void
    {
        $crawler = $this->client->request('GET', '/contacts');
        $links = $crawler->filter('a[data-contact-new]');
        self::assertGreaterThan(0, $links->count());
        foreach ($links as $link) {
            self::assertInstanceOf(\DOMElement::class, $link);
            self::assertSame(self::URL, $link->getAttribute('href'));
            self::assertTrue($link->hasAttribute('data-dialog'));
            self::assertSame('follow', $link->getAttribute('data-dialog-then'));
            self::assertSame('dialog', $link->getAttribute('aria-haspopup'));
        }

        $crawler = $this->open();
        self::assertResponseIsSuccessful();
        self::assertSame('New contact', $crawler->filter('dialog h2')->text());
        $form = $crawler->filter('form#contact-quick-form');
        self::assertSame(self::URL, $form->attr('action'));
        // The roles are chosen here, a customer to begin with.
        self::assertCount(0, $form->filter('input[type="hidden"][name="roles[]"]'));
        self::assertSame(['customer'], $form->filter('input[type="checkbox"][name="roles[]"]:checked')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertGreaterThan(1, $form->filter('input[type="checkbox"][name="roles[]"]')->count());
        // The whole form replaces the list, as its page does.
        $full = $crawler->filter('a[data-full-form]');
        self::assertSame('/contacts/new', $full->attr('href'));
        self::assertNull($full->attr('target'));
        self::assertSame('Create contact', trim($crawler->filter('footer button[type="submit"][form="contact-quick-form"]')->text()));
    }

    public function testAMissingNameIsToldInTheDialog(): void
    {
        $this->post(['displayName' => '', 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '100 00']);

        self::assertResponseStatusCodeSame(422);
        $crawler = new Crawler((string) $this->client->getResponse()->getContent());
        self::assertSame('dialog-page', $crawler->filter('dialog')->attr('id'));
        self::assertSame('The contact is not saved yet', trim($crawler->filter('[data-error-summary] p')->first()->text()));
        self::assertSame('true', $crawler->filter('#displayName')->attr('aria-invalid'));
        self::assertSame('Example City', $crawler->filter('#city')->attr('value'));
        self::assertSame(self::URL, $crawler->filter('form#contact-quick-form')->attr('action'));
    }

    public function testASavedContactOpensAsItsPage(): void
    {
        $this->post(['displayName' => 'Example Supplier s.r.o.', 'registrationNumber' => '12345687', 'roles' => ['supplier'], 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '100 00']);

        self::assertResponseIsSuccessful();
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($result);
        self::assertArrayNotHasKey('event', $result);
        self::assertIsString($result['location']);
        self::assertMatchesRegularExpression('#^/contacts/[0-9a-f-]{36}$#', $result['location']);
        $party = $this->stored(substr($result['location'], \strlen('/contacts/')));
        self::assertSame('Example Supplier s.r.o.', $party->displayName());
        self::assertSame([PartyRole::Supplier], $party->roles());

        $this->client->request('GET', $result['location']);
        self::assertSelectorTextContains('main', 'The contact is saved.');
    }

    public function testWithoutJavaScriptTheLinkIsTheWholeForm(): void
    {
        $crawler = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('main'));
        self::assertCount(0, $crawler->filter('form#contact-quick-form'));
    }

    private function open(): Crawler
    {
        $this->client->request('GET', self::URL, server: self::DIALOG);

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * @param array<string, string|list<string>> $typed
     */
    private function post(array $typed): void
    {
        $values = $this->open()->filter('form#contact-quick-form')->form()->getPhpValues();
        foreach ($typed as $name => $value) {
            $values[$name] = $value;
        }
        $this->client->request('POST', self::URL, $values, server: self::DIALOG);
    }

    private function stored(string $id): Party
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $party = $em->find(Party::class, $id);
        self::assertInstanceOf(Party::class, $party);

        return $party;
    }
}
