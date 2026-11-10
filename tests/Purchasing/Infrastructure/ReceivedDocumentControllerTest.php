<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Infrastructure;

use App\ExchangeRate\Application\RateSource;
use App\Identity\Domain\Role;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Purchasing\Application\ReceivedDocumentWarning;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\ReceivedDocumentState;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\ExchangeRate\Support\FakeRateSource;
use App\Tests\Identity\Support\SignedInBrowser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Yaml\Yaml;

/**
 * The screens of received documents: the list with its tabs and filters, the
 * upload, recording a draft and a new document, editing, the trash and a
 * restore, deleting a draft, and a guest kept out. Every write goes through
 * the HTTP layer and is read back from the database.
 */
final class ReceivedDocumentControllerTest extends WebTestCase
{
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1', 'HTTP_ACCEPT' => 'application/json, text/html'];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private TenantId $tenant;
    private LegalEntity $firm;
    private Party $supplier;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-10 09:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $rates = new FakeRateSource();
        $rates->publish('2026-10-28', '24.310', '15.120');
        self::getContainer()->set(RateSource::class, $rates);
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em(), $this->context);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheListOpensOnTheDraftsAndTheSidebarCountsThem(): void
    {
        $this->signedIn();
        $this->upload(['first.pdf' => '%PDF-1.4 first', 'second.pdf' => '%PDF-1.4 second']);

        $crawler = $this->page('/purchasing');

        self::assertSame('page', $crawler->filter('main nav a[href="/purchasing"]')->attr('aria-current'));
        self::assertStringContainsString('To complete', $crawler->filter('main nav a[href="/purchasing"]')->text());
        self::assertCount(2, $crawler->filter('main table tbody tr'));
        self::assertStringContainsString('Draft of', $crawler->filter('main table tbody')->text());
        $sidebar = $crawler->filter('#sidebar a[href="/purchasing"]');
        self::assertStringContainsString('Costs', $sidebar->text());
        self::assertStringContainsString('2', $sidebar->text());
        // Every action sits in the page header.
        self::assertCount(1, $crawler->filter('[data-page-actions] a[href="/purchasing/upload"][data-dialog]'));
        self::assertCount(1, $crawler->filter('[data-page-actions] a[href="/purchasing/new"][data-dialog]'));
    }

    public function testUploadingManyFilesMakesADraftOfEachAndTellsARefusedOne(): void
    {
        $this->signedIn();

        $this->upload(['first.pdf' => '%PDF-1.4 first', 'empty.pdf' => '', 'second.pdf' => '%PDF-1.4 second']);

        self::assertResponseRedirects('/purchasing', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('2 documents uploaded', $crawler->filter('main')->text());
        self::assertStringContainsString('empty.pdf', $crawler->filter('main [role="alert"]')->text());
        self::assertSame(2, $this->documentsIn(ReceivedDocumentState::Draft));
    }

    public function testTheDialogUploadsOneFileARequestAndAnswersJson(): void
    {
        $this->signedIn();
        $token = $this->token('/purchasing/upload');

        $this->client->request('POST', '/purchasing/upload', ['_token' => $token, 'firm' => $this->firm->id()], ['file' => $this->file('%PDF-1.4 one', 'one.pdf')], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(201);
        $answer = $this->json();
        self::assertIsString($answer['id']);
        self::assertSame('/purchasing/'.$answer['id'], $answer['url']);

        $this->client->request('POST', '/purchasing/upload', ['_token' => $token, 'firm' => $this->firm->id()], ['file' => $this->file('%PDF-1.4 one', 'again.pdf')], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(201, 'The same file again only warns.');
        self::assertSame(['The same file is on another document already.'], $this->json()['warnings']);
    }

    public function testADraftShowsItsOriginalBesideTheFormAndIsRecorded(): void
    {
        $this->signedIn();
        $id = $this->draft();

        $crawler = $this->page('/purchasing/'.$id);

        $preview = $crawler->filter('[data-purchasing-original] iframe[data-purchasing-preview="pdf"]');
        self::assertCount(1, $preview);
        self::assertStringStartsWith('/attachments/', (string) $preview->attr('src'));
        self::assertCount(1, $crawler->filter('[data-page-actions] button[form="received-document-form"][value="record"]'));
        self::assertCount(1, $crawler->filter('[data-page-actions] a[href="/purchasing/'.$id.'/trash"][data-dialog]'));
        self::assertCount(1, $crawler->filter('[data-page-actions] form[action="/purchasing/'.$id.'/delete"]'));

        $this->post($id, $this->values($crawler), 'record');

        self::assertResponseRedirects('/purchasing/'.$id, 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Document R20260001 is recorded.', $crawler->filter('main')->text());
        self::assertSame('R20260001', $crawler->filter('main h1')->text());
        $document = $this->document($id);
        self::assertSame(ReceivedDocumentState::Recorded, $document->state());
        self::assertSame('FV-2026-123', $document->supplierNumber());
        self::assertSame(121_000, $document->total()?->minor());
        self::assertSame(21_000, $document->vatCzk()?->minor());
        self::assertStringContainsString('recorded the document', $crawler->filter('[data-purchasing-history]')->text());
        self::assertStringContainsString('1,210.00', $crawler->filter('[data-purchasing-figures]')->text());

        $list = $this->page('/purchasing?tab=recorded');
        self::assertStringContainsString('R20260001', $list->filter('main table tbody')->text());
        self::assertStringContainsString('Supplier Example s.r.o.', $list->filter('main table tbody')->text());
    }

    public function testARefusalIsToldAtItsFieldAndInTheSummary(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $values = ['supplierId' => '', 'supplierQuery' => ''] + $this->values($this->page('/purchasing/'.$id));

        $crawler = $this->post($id, $values, 'record');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Pick the supplier.', $crawler->filter('[data-error-summary]')->text());
        self::assertSame('#supplier', $crawler->filter('[data-error-summary] a')->attr('href'));
        self::assertSame('true', $crawler->filter('#supplier')->attr('aria-invalid'));
        self::assertSame(ReceivedDocumentState::Draft, $this->document($id)->state());
    }

    public function testATypedValueThatIsNoAmountIsToldAtTheRowOfTheRecap(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $values = $this->values($this->page('/purchasing/'.$id));
        $values['recap'] = [['rate' => '21', 'base' => 'a lot', 'vat' => '210.00', 'statedVatCzk' => '']];

        $crawler = $this->post($id, $values, 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#recap-0-base')->attr('aria-invalid'));
        self::assertStringContainsString('Row 1: Base', $crawler->filter('[data-error-summary]')->text());
    }

    public function testRowsOfTheRecapAreAddedAndRemovedWithoutSaving(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $version = $this->document($id)->version();
        $values = $this->values($this->page('/purchasing/'.$id));

        $crawler = $this->post($id, $values, 'add_row');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-recap-row]'));
        $values = $this->values($crawler);
        $crawler = $this->post($id, $values, 'remove_row:0');
        self::assertCount(1, $crawler->filter('[data-recap-row]'));
        self::assertSame('', $crawler->filter('#recap-0-base')->attr('value'));
        self::assertSame($version, $this->document($id)->version(), 'Nothing was saved.');
    }

    public function testARecordedDocumentIsChangedAndItsHistorySaysWhat(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $values = $this->values($this->page('/purchasing/'.$id));
        $values['supplierNumber'] = 'FV-2026-999';

        $this->post($id, $values, 'save');

        self::assertResponseRedirects('/purchasing/'.$id, 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Document R20260001 is saved.', $crawler->filter('main')->text());
        self::assertSame('FV-2026-999', $this->document($id)->supplierNumber());
        self::assertStringContainsString("changed: Supplier's document number", $crawler->filter('[data-purchasing-history]')->text());
        self::assertCount(0, $crawler->filter('[data-page-actions] button[value="record"]'));
        self::assertCount(0, $crawler->filter('[data-page-actions] form[action$="/delete"]'), 'Only a draft is deleted.');
    }

    public function testAFormOpenedBeforeTheLastSaveIsRefused(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $values = $this->values($this->page('/purchasing/'.$id));
        $values['version'] = '1';

        $crawler = $this->post($id, $values, 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-reload-prompt]'));
    }

    public function testWarningsDoNotStopTheSave(): void
    {
        $this->signedIn();
        $first = $this->recorded();
        $second = $this->draft('%PDF-1.4 another file');

        $this->post($second, $this->values($this->page('/purchasing/'.$second)), 'record');

        self::assertResponseRedirects('/purchasing/'.$second, 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString("The supplier's number is on a recorded document already.", $crawler->filter('[data-purchasing-warning]')->text());
        self::assertSame(ReceivedDocumentState::Recorded, $this->document($second)->state());
        self::assertNotSame($first, $second);
    }

    public function testADocumentGoesToTheTrashWithAReasonAndComesBack(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $token = $this->token('/purchasing/'.$id);

        $this->client->request('POST', '/purchasing/'.$id.'/trash', ['_token' => $token, 'reason' => ''], server: self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Give a reason.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/purchasing/'.$id.'/trash', ['_token' => $token, 'reason' => 'Uploaded twice'], server: self::DIALOG);
        self::assertSame(['location' => '/purchasing/'.$id], $this->json());
        $page = $this->page('/purchasing/'.$id);
        self::assertStringContainsString('Uploaded twice', $page->filter('[data-purchasing-trashed]')->text());
        self::assertCount(1, $page->filter('[data-page-actions] form[action="/purchasing/'.$id.'/restore"]'));
        self::assertCount(0, $page->filter('[data-page-actions] button[value="save"]'));
        self::assertStringContainsString('R20260001', $this->page('/purchasing?tab=trash')->filter('main table tbody')->text());

        $this->client->request('POST', '/purchasing/'.$id.'/restore', ['_token' => $token]);

        self::assertResponseRedirects('/purchasing/'.$id, 303);
        self::assertFalse($this->document($id)->isTrashed());
        self::assertStringContainsString('restored the document from the trash', $this->page('/purchasing/'.$id)->filter('[data-purchasing-history]')->text());
    }

    public function testADraftIsDeletedForGood(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $token = $this->token('/purchasing/'.$id);

        $this->client->request('POST', '/purchasing/'.$id.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/purchasing', 303);
        $this->client->request('GET', '/purchasing/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testARecordedDocumentIsNotDeleted(): void
    {
        $this->signedIn();
        $id = $this->recorded();

        $this->client->request('POST', '/purchasing/'.$id.'/delete', ['_token' => $this->token('/purchasing/'.$id)]);

        self::assertResponseRedirects('/purchasing/'.$id, 303);
        self::assertStringContainsString('The document is recorded already.', $this->client->followRedirect()->filter('main')->text());
    }

    public function testANewDocumentWithoutAFileIsRecordedFromTheDialogWhichLeadsToIt(): void
    {
        $this->signedIn();
        $dialog = $this->dialog('/purchasing/new');
        self::assertCount(1, $dialog->filter('form#received-document-new'));
        $values = $this->values($dialog) + ['firm' => $this->firm->id()];

        $this->client->request('POST', '/purchasing/new', $values + ['_action' => 'record'], server: self::DIALOG);

        $location = $this->json()['location'] ?? null;
        self::assertIsString($location);
        self::assertMatchesRegularExpression('#^/purchasing/[0-9a-f-]{36}$#', $location);
        $document = $this->document(substr($location, \strlen('/purchasing/')));
        self::assertSame(ReceivedDocumentState::Recorded, $document->state());
        self::assertNull($document->originalAttachmentId());
    }

    public function testANewDocumentMissingItsTotalStaysInTheDialog(): void
    {
        $this->signedIn();
        $values = ['total' => ''] + $this->values($this->dialog('/purchasing/new')) + ['firm' => $this->firm->id()];

        $this->client->request('POST', '/purchasing/new', $values + ['_action' => 'record'], server: self::DIALOG);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Fill in the total to pay.', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->documentsIn(ReceivedDocumentState::Recorded));
    }

    public function testADocumentInAnotherCurrencyTakesTheRateOfItsTaxPointAndShowsItsDay(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $values = ['currency' => 'EUR', 'kind' => 'foreign_invoice', 'treatment' => 'eu_service'] + $this->values($this->page('/purchasing/'.$id));
        $values['recap'] = [['rate' => '21', 'base' => '100', 'vat' => '0', 'statedVatCzk' => '']];
        $values['total'] = '100';

        $this->post($id, $values, 'record');

        self::assertResponseRedirects('/purchasing/'.$id, 303);
        $document = $this->document($id);
        self::assertSame('24.31', $document->exchangeRate()?->toString());
        self::assertSame(243_100, $document->baseCzk()?->minor());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('ČNB rate published on', $crawler->filter('main')->text());
    }

    public function testTheListIsFilteredBySupplierMonthAndText(): void
    {
        $this->signedIn();
        $this->recorded();
        $this->draft('%PDF-1.4 still a draft');

        $found = $this->page('/purchasing?tab=recorded&q=fv-2026&month=2026-10&supplier='.$this->supplier->id());
        self::assertCount(1, $found->filter('main table tbody tr'));
        $none = $this->page('/purchasing?tab=recorded&month=2026-09');
        self::assertCount(0, $none->filter('main table tbody tr'));
        self::assertStringContainsString('No document matches the filter', $none->filter('main')->text());
        $tabs = $found->filter('main nav[aria-label="Received document views"]')->text();
        self::assertStringContainsString('Recorded', $tabs);
    }

    public function testTheSupplierComboboxFindsContacts(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/purchasing/suppliers?q=Supplier');

        self::assertResponseIsSuccessful();
        self::assertSame([['id' => $this->supplier->id(), 'label' => 'Supplier Example s.r.o.', 'hint' => '87654321']], $this->json()['results']);
        $this->client->request('GET', '/purchasing/suppliers?id='.$this->supplier->id());
        self::assertSame($this->supplier->id(), $this->json()['results'][0]['id']);
    }

    public function testAnUnknownDocumentIs404(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/purchasing/'.Id::generate());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestIsKeptOut(): void
    {
        $this->signedIn(Role::Guest);

        foreach (['/purchasing', '/purchasing/upload', '/purchasing/new', '/purchasing/suppliers?q=Supplier', '/purchasing/'.Id::generate()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertCount(0, $this->client->request('GET', '/shared')->filter('#sidebar a[href="/purchasing"]'));
    }

    public function testEveryRefusalAndWarningIsTranslated(): void
    {
        foreach (['cs', 'en'] as $locale) {
            /** @var array{purchasing: array{refused: array<string, string>, warning: array<string, string>}} $messages */
            $messages = Yaml::parseFile(\dirname(__DIR__, 3).'/translations/messages+intl-icu.'.$locale.'.yaml');
            $warnings = array_values(array_filter(new \ReflectionClass(ReceivedDocumentWarning::class)->getConstants(), \is_string(...)));
            self::assertEqualsCanonicalizing(ReceivedDocumentRefused::REASONS, array_keys($messages['purchasing']['refused']), $locale);
            self::assertEqualsCanonicalizing($warnings, array_keys($messages['purchasing']['warning']), $locale);
        }
    }

    private function signedIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], role: $role);
        $this->scoped(function (): void {
            $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, '');
            $this->em()->persist($this->firm);
            $this->supplier = new Party(PartyKind::Company, 'Supplier Example s.r.o.', new RegistrationNumber('CZ', '87654321'), VatId::fromString('CZ87654321'), '');
            $this->em()->persist($this->supplier);
            $this->em()->persist(new PartyAddress($this->supplier, AddressType::Billing, new Address('Supplier Street 2', 'Example City', '110 00', 'CZ')));
            $this->em()->flush();
        });
        $this->browser->signIn();
    }

    /**
     * Posts the files as the form does without JavaScript.
     *
     * @param array<string, string> $files content by name
     */
    private function upload(array $files): void
    {
        $token = $this->token('/purchasing/upload');
        $this->client->request('POST', '/purchasing/upload', ['_token' => $token, 'firm' => $this->firm->id()], ['files' => array_map($this->file(...), $files, array_keys($files))]);
    }

    private function draft(string $content = "%PDF-1.4\nAn example invoice\n%%EOF"): string
    {
        $this->client->request('POST', '/purchasing/upload', ['_token' => $this->token('/purchasing/upload'), 'firm' => $this->firm->id()], ['file' => $this->file($content, 'invoice.pdf')], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];
        self::assertIsString($id);

        return $id;
    }

    private function recorded(): string
    {
        $id = $this->draft();
        $this->post($id, $this->values($this->page('/purchasing/'.$id)), 'record');
        self::assertResponseRedirects('/purchasing/'.$id, 303);

        return $id;
    }

    /**
     * The values of the form on the page, filled in as a domestic tax invoice of the supplier
     * for 1,000 CZK and 21 % VAT where the form leaves them empty.
     *
     * @return array<string, mixed>
     */
    private function values(Crawler $crawler): array
    {
        $values = [];
        foreach ($crawler->filter('form#received-document-form, form#received-document-new')->form()->getPhpValues() as $field => $value) {
            $values[(string) $field] = $value;
        }
        unset($values['_action']);
        $defaults = [
            'supplierId' => $this->supplier->id(),
            'supplierQuery' => 'Supplier Example s.r.o.',
            'supplierNumber' => 'FV-2026-123',
            'variableSymbol' => '2026123',
            'issueDate' => '2026-10-28',
            'taxPointDate' => '2026-10-28',
            'dueDate' => '2026-11-11',
            'total' => '1210.00',
        ];
        foreach ($defaults as $field => $value) {
            if ('' === ($values[$field] ?? '')) {
                $values[$field] = $value;
            }
        }
        $recap = \is_array($values['recap'] ?? null) ? $values['recap'] : [];
        $first = \is_array($recap[0] ?? null) ? $recap[0] : [];
        if ('' === ($first['base'] ?? '')) {
            $recap[0] = ['rate' => '21', 'base' => '1000.00', 'vat' => '210.00', 'statedVatCzk' => ''];
        }
        $values['recap'] = $recap;

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function post(string $id, array $values, string $action): Crawler
    {
        return $this->client->request('POST', '/purchasing/'.$id, ['_action' => $action] + $values);
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
        $this->client->request('GET', $url, server: self::DIALOG);
        self::assertResponseIsSuccessful();

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
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
        return $this->scoped(function () use ($id): ReceivedDocument {
            $this->em()->clear();

            return $this->em()->find(ReceivedDocument::class, $id) ?? throw new \LogicException('No such document.');
        });
    }

    private function documentsIn(ReceivedDocumentState $state): int
    {
        return $this->scoped(fn (): int => \count($this->em()->getRepository(ReceivedDocument::class)->findBy(['state' => $state])));
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(callable $work): mixed
    {
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
