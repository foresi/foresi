<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Infrastructure\Messenger\ReadReceivedDocumentHandler;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Support\IsdocFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The reading of a received document on its screens: the state and the sources
 * over the fields, a mark at each value read, the suggestions to take, the
 * supplier to create, read again in the header, and the To review view.
 */
final class ReceivedDocumentReadingTest extends WebTestCase
{
    private KernelBrowser $client;
    private MockClock $clock;
    private SignedInBrowser $browser;
    private Party $supplier;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-10 09:00:00', 'UTC');
        Clock::set($this->clock);
        $this->client = self::createClient();
        $this->client->disableReboot();
        $rates = new FakeRateSource();
        $rates->publish('2026-10-28', '24.310', '15.120');
        self::getContainer()->set(RateSource::class, $rates);
        $this->browser = new SignedInBrowser($this->client, $this->em());
        $this->browser->seed();
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, '');
        $this->em()->persist($firm);
        $this->supplier = new Party(PartyKind::Company, 'Supplier Example s.r.o.', new RegistrationNumber('CZ', '87654321'), VatId::fromString('CZ87654321'), '');
        $this->em()->persist($this->supplier);
        $this->em()->persist(new PartyAddress($this->supplier, AddressType::Billing, new Address('Supplier Street 2', 'Example City', '110 00', 'CZ')));
        $this->em()->flush();
        $this->browser->signIn();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testDetailShowsSourcesAndSuggestions(): void
    {
        // Read into an untouched draft: the values are filled, each marked with its source.
        $read = $this->draft(IsdocFixtures::invoice(), 'invoice.isdoc');
        $this->readQueued();

        $crawler = $this->page('/purchasing/'.$read);
        $panel = $crawler->filter('[data-purchasing-reading="read"]');
        self::assertCount(1, $panel);
        self::assertStringContainsString('Read from ISDOC', $panel->text());
        self::assertStringContainsString('No contact has the supplier', $panel->text());
        self::assertStringContainsString('From ISDOC', $crawler->filter('#total-help')->text());
        self::assertStringContainsString('From ISDOC', $crawler->filter('#supplierNumber-help')->text());
        self::assertStringContainsString('From ISDOC', $crawler->filter('[data-recap] [data-reading-mark]')->text());
        self::assertCount(0, $crawler->filter('[data-purchasing-suggestions]'));
        // Nobody has the supplier's numbers: the contact dialog opens with what the file says.
        $create = $crawler->filter('a#supplier-add[data-dialog]');
        self::assertSame('Create supplier', trim($create->text()));
        self::assertStringContainsString('displayName=Cloud%20Host%20s.r.o.', (string) $create->attr('href'));
        self::assertStringContainsString('registrationNumber=12345678', (string) $create->attr('href'));
        self::assertStringContainsString('vatId=CZ12345678', (string) $create->attr('href'));
        $dialog = $this->dialog((string) $create->attr('href'));
        self::assertSame('Cloud Host s.r.o.', $dialog->filter('#displayName')->attr('value'));
        self::assertSame('12345678', $dialog->filter('#registrationNumber')->attr('value'));
        // Read again sits in the menu of the page header.
        self::assertCount(1, $crawler->filter('[data-page-actions] form[action="/purchasing/'.$read.'/read-again"]'));

        // Saved by a person before the reading: what was read waits as suggestions.
        $edited = $this->draft(IsdocFixtures::invoice(), 'edited.isdoc');
        $queued = $this->queued();
        $this->client->request('POST', '/purchasing/'.$edited, $this->personValues($edited));
        self::assertResponseRedirects('/purchasing/'.$edited, 303);
        $this->read($queued);

        $crawler = $this->page('/purchasing/'.$edited);
        $suggestions = $crawler->filter('[data-purchasing-suggestions]');
        self::assertCount(1, $suggestions);
        self::assertStringContainsString('Found in the file', $suggestions->text());
        $offered = $suggestions->filter('input[type="checkbox"][name="fields[]"]')->each(static fn (Crawler $box): string => (string) $box->attr('value'));
        self::assertContains('total', $offered);
        self::assertContains('vat_recap', $offered);
        self::assertContains('supplier_number', $offered);
        self::assertStringContainsString('12,100.00', $suggestions->text());
        self::assertCount(0, $crawler->filter('#total-help'), 'The person typed another total.');

        $form = $suggestions->filter('form')->form();
        $this->client->request('POST', $form->getUri(), ['fields' => ['total', 'vat_recap']] + $form->getValues());
        self::assertResponseRedirects('/purchasing/'.$edited, 303);

        $document = $this->document($edited);
        self::assertSame(1_210_000, $document->total()?->minor());
        self::assertSame('FV-2026-123', $document->supplierNumber(), 'Not chosen, so kept.');
        $crawler = $this->page('/purchasing/'.$edited);
        self::assertStringContainsString('From ISDOC', $crawler->filter('#total-help')->text());
        $offered = $crawler->filter('[data-purchasing-suggestions] input[name="fields[]"]')->each(static fn (Crawler $box): string => (string) $box->attr('value'));
        self::assertNotContains('total', $offered);
        self::assertContains('supplier_number', $offered);
    }

    public function testToReviewViewListsReadDraftsOnly(): void
    {
        $read = $this->draft(IsdocFixtures::invoice(), 'invoice.isdoc');
        $this->readQueued();
        $reading = $this->draft("%PDF-1.4\nStill being read\n%%EOF", 'waiting.pdf');
        $recorded = $this->draft(IsdocFixtures::invoice(), 'recorded.isdoc');
        $this->readQueued();
        $this->em()->clear();
        $this->client->request('POST', '/purchasing/'.$recorded, ['_action' => 'record'] + $this->personValues($recorded));
        self::assertResponseRedirects('/purchasing/'.$recorded, 303);

        $crawler = $this->page('/purchasing');
        self::assertContains('To review1', $this->tabs($crawler));
        $row = $crawler->filter('main table tbody tr')->reduce(static fn (Crawler $tr): bool => 1 === $tr->filter('a[href="/purchasing/'.$reading.'"]')->count());
        self::assertStringContainsString('Reading', $row->text());
        self::assertStringContainsString('Upload', $row->text());

        $crawler = $this->page('/purchasing?view=to_review');
        $rows = $crawler->filter('main table tbody tr');
        self::assertCount(1, $rows);
        self::assertCount(1, $rows->filter('a[href="/purchasing/'.$read.'"]'));
        self::assertStringContainsString('Read', $rows->text());
    }

    public function testThePolledFrameOfADraftThatLeftIsEmpty(): void
    {
        $id = $this->draft("%PDF-1.4\nStill being read\n%%EOF", 'waiting.pdf');

        $frame = $this->page('/purchasing/'.$id.'/reading')->filter('turbo-frame#received-document-reading');
        self::assertCount(1, $frame);
        self::assertSame('true', $frame->filter('[data-progress-active]')->attr('data-progress-active'));

        $this->client->request('POST', '/purchasing/'.$id.'/trash', ['_token' => $this->token('/purchasing/'.$id), 'reason' => 'Twice'], server: ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertTrue($this->document($id)->isTrashed());

        // No reading to show: an empty frame, which stops the polling, never an error.
        $frame = $this->page('/purchasing/'.$id.'/reading')->filter('turbo-frame#received-document-reading');
        self::assertCount(1, $frame);
        self::assertCount(0, $frame->filter('[data-progress-active], [data-purchasing-reading]'));
    }

    public function testAReadingStuckForTenMinutesShowsAsFailedWithReadAgain(): void
    {
        $id = $this->draft("%PDF-1.4\nNever read\n%%EOF", 'stuck.pdf');
        $crawler = $this->page('/purchasing/'.$id);
        self::assertCount(1, $crawler->filter('turbo-frame[data-controller="import-progress"] [data-purchasing-reading="reading"]'));
        self::assertCount(0, $crawler->filter('[data-page-actions] form[action="/purchasing/'.$id.'/read-again"]'));

        $this->clock->modify('+11 minutes');

        $crawler = $this->page('/purchasing/'.$id);
        self::assertCount(1, $crawler->filter('[data-purchasing-reading="failed"]'));
        self::assertCount(0, $crawler->filter('turbo-frame[data-controller]'), 'No endless polling.');
        self::assertCount(1, $crawler->filter('[data-page-actions] form[action="/purchasing/'.$id.'/read-again"]'));
        self::assertCount(0, $this->page('/purchasing/'.$id.'/reading')->filter('[data-progress-active="true"]'));
    }

    public function testReadAgainAndAcceptRefuseABadTokenAndAGuest(): void
    {
        $id = $this->draft(IsdocFixtures::invoice(), 'invoice.isdoc');
        $this->readQueued();
        $version = (string) $this->document($id)->version();
        $posts = [
            '/purchasing/'.$id.'/read-again' => [],
            '/purchasing/'.$id.'/accept-reading' => ['version' => $version, 'fields' => ['total']],
        ];
        foreach ($posts as $url => $values) {
            $this->client->request('POST', $url, ['_token' => 'not-the-token'] + $values);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $token = $this->token('/purchasing/'.$id);

        $this->scopedGuest();
        foreach ($posts as $url => $values) {
            $this->client->request('POST', $url, ['_token' => $token] + $values);
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertSame((int) $version, $this->document($id)->version(), 'Nothing changed.');
    }

    public function testTheNewContactTakesItsPrefillAsPlainCutValues(): void
    {
        $query = http_build_query(['displayName' => '<script>alert(1)</script>', 'registrationNumber' => str_repeat('1', 100), 'vatId' => 'CZ12345678', 'countryCode' => 'XX']);
        $dialog = $this->dialog('/contacts/new?'.$query);
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $this->client->getResponse()->getContent());
        self::assertSame('<script>alert(1)</script>', $dialog->filter('#displayName')->attr('value'));
        self::assertSame(str_repeat('1', 64), $dialog->filter('#registrationNumber')->attr('value'));
        self::assertSame('CZ12345678', $dialog->filter('input[name="vatId"]')->attr('value'));
        // A country the form does not offer is left out: both keep the default.
        self::assertSame('CZ', $dialog->filter('input[name="registrationCountry"]')->attr('value'));
        self::assertSame('CZ', $dialog->filter('#countryCode option[selected]')->attr('value'));

        $dialog = $this->dialog('/contacts/new?countryCode=sk');
        self::assertSame('SK', $dialog->filter('input[name="registrationCountry"]')->attr('value'));
        self::assertSame('SK', $dialog->filter('#countryCode option[selected]')->attr('value'));
        // The page form names the same fields.
        $page = $this->page('/contacts/new?countryCode=SK');
        self::assertSame('SK', $page->filter('#registrationCountry option[selected]')->attr('value'));
        self::assertSame('SK', $page->filter('#countryCode option[selected]')->attr('value'));
    }

    /**
     * The person signed in becomes a guest of the organisation, the session kept.
     */
    private function scopedGuest(): void
    {
        $this->em()->clear();
        $membership = $this->em()->getRepository(Membership::class)->findOneBy([]) ?? throw new \LogicException('No membership.');
        $party = $this->em()->find(Party::class, $this->supplier->id()) ?? throw new \LogicException('No party.');
        $membership->changeRole(Role::Guest, $party);
        $this->em()->flush();
    }

    /**
     * The form of the document as a person saves it: a domestic tax invoice of the supplier
     * for 1,000 CZK and 21 % VAT.
     *
     * @return array<string, mixed>
     */
    private function personValues(string $id): array
    {
        $values = [];
        foreach ($this->page('/purchasing/'.$id)->filter('form#received-document-form')->form()->getPhpValues() as $field => $value) {
            $values[(string) $field] = $value;
        }

        return [
            '_action' => 'save',
            'supplierId' => $this->supplier->id(),
            'supplierQuery' => 'Supplier Example s.r.o.',
            'supplierNumber' => 'FV-2026-123',
            'variableSymbol' => '2026123',
            'issueDate' => '2026-10-28',
            'taxPointDate' => '2026-10-28',
            'dueDate' => '2026-11-11',
            'total' => '1210.00',
            'recap' => [['rate' => '21', 'base' => '1000.00', 'vat' => '210.00', 'statedVatCzk' => '']],
        ] + $values;
    }

    /**
     * Runs the readings the last request queued, as the worker would.
     */
    private function readQueued(): void
    {
        $this->read($this->queued());
    }

    /**
     * The readings the last request queued: the test client resets the transport with the next request.
     *
     * @return list<ReadReceivedDocument>
     */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ReadReceivedDocument) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param list<ReadReceivedDocument> $messages
     */
    private function read(array $messages): void
    {
        foreach ($messages as $message) {
            self::getContainer()->get(ReadReceivedDocumentHandler::class)($message);
        }
        $this->em()->clear();
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Received document views"] a')->each(static fn (Crawler $tab): string => trim((string) preg_replace('/\s+/', ' ', $tab->text())));
    }

    private function draft(string $content, string $name): string
    {
        $this->client->request('POST', '/purchasing/upload', ['_token' => $this->token('/purchasing/upload')], ['file' => $this->file($content, $name)], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(201);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['id']);

        return $decoded['id'];
    }

    private function token(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = $this->client->getCrawler()->filter('main form[action^="/purchasing"] input[name="_token"]')->first()->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function page(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler;
    }

    private function dialog(string $url): Crawler
    {
        $this->client->request('GET', $url, server: ['HTTP_X_FORESI_DIALOG' => '1', 'HTTP_ACCEPT' => 'application/json, text/html']);
        self::assertResponseIsSuccessful();

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    private function file(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'received');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function document(string $id): ReceivedDocument
    {
        $this->em()->clear();

        return $this->em()->find(ReceivedDocument::class, $id) ?? throw new \LogicException('No such document.');
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
