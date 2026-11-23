<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Country;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

final class LegalEntityControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testAnInvalidRegistrationNumberIsAFormError(): void
    {
        $this->signIn();

        $this->submitNew(['registrationNumber' => '12345678']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#registrationNumber[aria-invalid="true"]');
    }

    public function testTheRegistrationNumberIsStoredWithoutItsSpaces(): void
    {
        $this->signIn();

        $this->submitNew(['registrationNumber' => '1234 5687']);

        self::assertResponseRedirects('/settings/firm');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $firms = $em->getRepository(LegalEntity::class)->findAll();
        self::assertCount(1, $firms);
        self::assertSame('12345687', $firms[0]->registrationNumber()->value);
    }

    private function signIn(string $locale = 'en'): void
    {
        $this->browser->seed($locale);
        $this->browser->signIn();
    }

    private function newForm(): Form
    {
        $crawler = $this->client->request('GET', '/settings/firm');

        return $crawler->filter('main form')->form([
            'name' => 'Example Company s.r.o.',
            'registrationCountry' => 'CZ',
            'registrationNumber' => '12345687',
            'vatStatus' => 'registered',
            'vatId' => 'CZ12345687',
            'street' => 'Example Street 1',
            'city' => 'Example City',
            'postalCode' => '100 00',
            'countryCode' => 'CZ',
        ]);
    }

    /**
     * @param array<string, string> $values
     */
    private function submitNew(array $values = []): void
    {
        $form = $this->newForm();
        // Posted directly, to send what the select does not offer.
        $this->client->request('POST', '/settings/firm', $values + $form->getPhpValues());
    }
}
