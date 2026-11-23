<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Import;

use Foresi\Import\Application\Source\SourceSettings;
use Foresi\Import\Contract\SourceFormat;
use Foresi\Import\Infrastructure\Source\OpenSpoutSourceReader;
use Foresi\Party\Application\CreateParty;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The export of the address book, which the Import module adds to the page
 * actions and the bulk actions of the list: the selected contacts, or what the
 * list shows.
 */
final class ContactListExportTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed('en');
        $browser->signIn();
    }

    public function testTheExportOfANarrowedViewHoldsItsConditions(): void
    {
        $this->party('Person Supplier', PartyKind::Person, [PartyRole::Supplier]);
        $this->party('Company Supplier', roles: [PartyRole::Supplier]);

        $this->client->request('GET', '/contacts?'.http_build_query(['view' => 'suppliers', 'conditions' => '1', 'c' => [
            ['field' => 'role', 'op' => 'is', 'values' => ['supplier']],
            ['field' => 'kind', 'op' => 'is', 'values' => ['person']],
        ]]));
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();

        self::assertSelectorCount(1, 'main tbody tr');
        self::assertCount(1, $crawler->filter('form[action="/contacts/export"] input[name="f"]'));
    }

    public function testCsvExportContainsOnlyTheSelectedContacts(): void
    {
        $mine = $this->party('Example Company s.r.o.', number: '12345687', vat: 'CZ12345687', email: 'info@example.com');
        $this->party('Not Selected');
        $formula = $this->party('=HYPERLINK("http://example.com")');

        $this->client->request('POST', '/contacts/export', ['_token' => $this->token(), 'ids' => [$mine->id(), $formula->id(), 'abc', '']]);

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename=contacts-\d{8}\.csv$/', (string) $response->headers->get('Content-Disposition'));
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF\"Číslo kontaktu ve Foresi\";Název;Druh;", $csv);
        self::assertStringContainsString(';"Example Company s.r.o.";Firma;12345687;', $csv);
        self::assertStringContainsString(';CZ12345687;info@example.com;', $csv);
        self::assertStringNotContainsString('Not Selected', $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertCount(3, array_filter(explode("\n", $csv), static fn (string $line): bool => '' !== $line), 'header and two selected contacts');
    }

    public function testXlsxExportIsAWorkbookWithTheSameContacts(): void
    {
        $mine = $this->party('Example Company s.r.o.', number: '12345687');

        $this->client->request('POST', '/contacts/export', ['_token' => $this->token(), 'ids' => [$mine->id()], 'format' => 'xlsx']);

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename=contacts-\d{8}\.xlsx$/', (string) $response->headers->get('Content-Disposition'));
        $content = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith('PK', $content);
        $path = tempnam(sys_get_temp_dir(), 'foresi-xlsx');
        file_put_contents($path, $content);
        try {
            $rows = [...new OpenSpoutSourceReader()->rows($path, new SourceSettings(SourceFormat::Xlsx, headerRow: 1))];
        } finally {
            unlink($path);
        }
        self::assertCount(1, $rows);
        self::assertContains('Example Company s.r.o.', $rows[0]);
    }

    /**
     * @param list<PartyRole> $roles
     */
    private function party(string $name, PartyKind $kind = PartyKind::SoleTrader, array $roles = [PartyRole::Customer], ?string $number = null, ?string $vat = null, string $email = ''): Party
    {
        // The fixture is set up by the application, not by the signed-in person.
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        $party = self::getContainer()->get(CreateParty::class)->create(
            null === $number && null === $vat ? $kind : PartyKind::Company,
            $name,
            null === $number ? null : new RegistrationNumber('CZ', $number),
            null === $vat ? null : VatId::fromString($vat),
            $email,
            '',
            $roles,
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
        );
        $tokens->setToken($signedIn);

        return $party;
    }

    private function token(): string
    {
        // The archive form of the list carries the token of the page actions (the list needs a contact for that).
        $crawler = $this->client->request('GET', '/contacts');

        return (string) $crawler->filter('form[action="/contacts/archive"] input[name="_token"]')->attr('value');
    }
}
