<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Documents\Application\LinkTargets;
use Foresi\Documents\Contract\RecordDocuments;
use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\DocumentLink;
use Foresi\Documents\Domain\DocumentStatus;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\PageSettings;
use Foresi\Documents\Domain\SignatureLevel;
use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Documents\Support\FakeLinkTarget;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The screens of Documents under Sales: the list with its filters, the new
 * document in its dialog, the detail with its header actions per state, the
 * signers by order, the signed copy, and the fragment another module shows
 * on a record. Only staff see them.
 */
final class DocumentScreensTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private User $user;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testTheOwnerFindsDocumentsUnderSalesAndFiltersThem(): void
    {
        $this->signedIn();
        [$nda, $template] = $this->ndaWithTemplate('<h1>Mutual NDA</h1>');
        $contract = $this->type('Service contract', 'service_contract');
        $this->document($nda, $template, 'NDA with Example Ltd');
        $this->document($contract, $this->template($contract, 'Contract', '<p>Contract</p>'), 'Contract with Example Ltd');

        $crawler = $this->client->request('GET', '/documents');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#sidebar [data-nav-sections] a[href="/documents"]'));
        self::assertCount(1, $crawler->filter('header a[data-document-new][data-dialog][href="/documents/new"]'));
        self::assertCount(2, $crawler->filter('[data-documents] tbody tr'));
        self::assertSelectorTextContains('[data-documents]', 'NDA with Example Ltd');
        self::assertSelectorTextContains('[data-documents]', 'Draft');

        $crawler = $this->client->request('GET', '/documents?type='.$contract->id());
        self::assertCount(1, $crawler->filter('[data-documents] tbody tr'));
        self::assertSelectorTextContains('[data-documents]', 'Contract with Example Ltd');

        $crawler = $this->client->request('GET', '/documents?status=completed');
        self::assertCount(0, $crawler->filter('[data-documents] tbody tr'));
        self::assertCount(1, $crawler->filter('[data-documents-filtered-empty]'));
    }

    public function testAGuestMayNotSeeDocuments(): void
    {
        $this->browser->seed(role: Role::Guest);
        $this->browser->signIn();

        $this->client->request('GET', '/documents');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/documents/for/contact/'.Id::generate());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/settings/documents');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheNewDocumentDialogCreatesADraftWithTheLinkFromTheAddress(): void
    {
        $this->signedIn();
        [$nda, $template] = $this->ndaWithTemplate('<h1>Mutual NDA</h1>');
        $contactId = Id::generate();

        $crawler = $this->client->request('GET', '/documents/new?link_kind=contact&link_id='.$contactId, server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#dialog-page #document-form'));
        self::assertSame($contactId, $crawler->filter('#document-form input[name="link_id"]')->attr('value'));
        self::assertSame('contact', $crawler->filter('#document-form input[name="link_kind"]')->attr('value'));
        // No module offers contacts here: the link shows as unavailable, and creating it is refused at the link.
        self::assertCount(1, $crawler->filter('#document-form [data-document-link-unavailable]'));
        $values = $crawler->filter('#document-form')->form()->getPhpValues();

        $crawler = $this->client->request('POST', '/documents/new', ['typeId' => $nda->id(), 'templateId' => $template->id(), 'title' => 'NDA with Example Ltd'] + $values, server: [$this->dialogHeader() => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('contact', $crawler->filter('#document-form')->text());

        // Without the link it is created and the dialog follows to the new document.
        $crawler = $this->client->request('GET', '/documents/new?type='.$nda->id(), server: [$this->dialogHeader() => '1']);
        $values = $crawler->filter('#document-form')->form()->getPhpValues();
        self::assertSame($nda->id(), $values['typeId']);
        // The templates are grouped under their type, the first of the type chosen picked.
        self::assertSame('NDA', $crawler->filter('#templateId optgroup')->attr('label'));
        self::assertSame($template->id(), $values['templateId']);
        $crawler = $this->client->request('POST', '/documents/new', ['templateId' => $template->id(), 'title' => ''] + $values, server: [$this->dialogHeader() => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#document-form [aria-invalid="true"]#title'));

        $this->client->request('POST', '/documents/new', ['templateId' => $template->id(), 'title' => 'NDA with Example Ltd'] + $values, server: [$this->dialogHeader() => '1']);
        $location = $this->location();
        self::assertMatchesRegularExpression('~^/documents/[0-9a-f-]{36}$~', $location);
        $document = $this->em()->getRepository(Document::class)->findOneBy(['title' => 'NDA with Example Ltd']);
        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentStatus::Draft, $document->status());
        self::assertSame('/documents/'.$document->id(), $location);
    }

    public function testADraftShowsItsTemplateAndGeneratesItsPdf(): void
    {
        $this->signedIn();
        [$nda, $template] = $this->ndaWithTemplate('<h1>Mutual NDA</h1><p>Between the parties.</p>');
        $document = $this->document($nda, $template, 'NDA with Example Ltd');

        $crawler = $this->client->request('GET', '/documents/'.$document->id());
        self::assertResponseIsSuccessful();
        self::assertSame('Draft', trim($crawler->filter('[data-document-status]')->text()));
        self::assertCount(1, $crawler->filter('header form[data-document-generate]'));
        self::assertCount(0, $crawler->filter('header form[data-document-send]'));
        self::assertStringContainsString('Mutual NDA', (string) $crawler->filter('iframe[data-document-preview="template"]')->attr('srcdoc'));

        $this->post('/documents/'.$document->id().'/generate', $crawler);
        self::assertResponseRedirects('/documents/'.$document->id());
        $crawler = $this->client->followRedirect();
        self::assertSame('Ready', trim($crawler->filter('[data-document-status]')->text()));
        $pdf = $crawler->filter('iframe[data-document-preview="pdf"]');
        self::assertCount(1, $pdf);
        self::assertMatchesRegularExpression('~^/attachments/[0-9a-f-]{36}$~', (string) $pdf->attr('src'));
        self::assertCount(1, $crawler->filter('header form[data-document-send]'));
        self::assertCount(0, $crawler->filter('header form[data-document-generate]'));

        $this->client->request('GET', (string) $pdf->attr('src'));
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testGenerateNamesTheVariablesTheTemplateMisses(): void
    {
        $this->signedIn();
        [$nda, $template] = $this->ndaWithTemplate('<p>{{ client.name }} and {{ deal.title }}</p>');
        $document = $this->document($nda, $template, 'NDA with Example Ltd');

        $crawler = $this->client->request('GET', '/documents/'.$document->id());
        self::assertSelectorTextContains('[data-document-preview-refused]', 'client.name');
        $this->post('/documents/'.$document->id().'/generate', $crawler);
        $crawler = $this->client->followRedirect();

        $error = $crawler->filter('[data-document-flash="error"]')->text();
        self::assertStringContainsString('client.name', $error);
        self::assertStringContainsString('deal.title', $error);
        self::assertSame('Draft', trim($crawler->filter('[data-document-status]')->text()));
    }

    public function testSignersByOrderTheSignedCopyAndBothSignaturesCompleteTheDocument(): void
    {
        $this->signedIn();
        [$nda, $template] = $this->ndaWithTemplate('<h1>Mutual NDA</h1>');
        $document = $this->document($nda, $template, 'NDA with Example Ltd');
        $crawler = $this->client->request('GET', '/documents/'.$document->id());
        $this->post('/documents/'.$document->id().'/generate', $crawler);

        // Two signers in the add dialog, one per order.
        $values = $this->dialogForm('/documents/'.$document->id().'/signers/new', '#signer-form');
        $this->dialogPost('/documents/'.$document->id().'/signers/new', ['name' => 'Jana Example', 'email' => 'jana@example.com', 'side' => 'theirs', 'order' => '1'] + $values);
        self::assertSame('/documents/'.$document->id(), $this->location());
        $this->dialogPost('/documents/'.$document->id().'/signers/new', ['name' => 'Petr Example', 'email' => 'not an address', 'side' => 'ours', 'order' => '2'] + $values);
        self::assertResponseStatusCodeSame(422);
        $this->dialogPost('/documents/'.$document->id().'/signers/new', ['name' => 'Petr Example', 'email' => 'petr@example.com', 'side' => 'ours', 'order' => '2'] + $values);

        $crawler = $this->client->request('GET', '/documents/'.$document->id());
        self::assertSame(['Jana Example'], $crawler->filter('[data-signer-order="1"] [data-signer-name]')->each(static fn (Crawler $n): string => trim($n->text())));
        self::assertSame(['Petr Example'], $crawler->filter('[data-signer-order="2"] [data-signer-name]')->each(static fn (Crawler $n): string => trim($n->text())));
        self::assertCount(2, $crawler->filter('header form[data-signer-mark-signed]'));

        // The signed copy in its dialog: a file that is not a PDF is refused at the field.
        $crawler = $this->client->request('GET', '/documents/'.$document->id().'/signed-copy', server: [$this->dialogHeader() => '1']);
        self::assertCount(1, $crawler->filter('#signed-copy-form input[type="file"][name="file"]'));
        $token = (string) $crawler->filter('#signed-copy-form input[name="_token"]')->attr('value');
        $crawler = $this->client->request('POST', '/documents/'.$document->id().'/signed-copy', ['_token' => $token], ['file' => $this->file('not a pdf', 'nda.txt')], server: [$this->dialogHeader() => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#signed-copy-form [aria-invalid="true"]#file'));
        $this->client->request('POST', '/documents/'.$document->id().'/signed-copy', ['_token' => $token], ['file' => $this->file('%PDF-1.4 signed', 'nda-signed.pdf')], server: [$this->dialogHeader() => '1']);
        self::assertSame('/documents/'.$document->id(), $this->location());

        $crawler = $this->client->request('GET', '/documents/'.$document->id());
        // The copy implies it went out.
        self::assertSame('Out for signature', trim($crawler->filter('[data-document-status]')->text()));
        self::assertCount(1, $crawler->filter('[data-document-signed-copy-link]'));
        self::assertCount(0, $crawler->filter('header a[data-document-signed-copy]'));

        foreach ($crawler->filter('header form[data-signer-mark-signed]')->each(static fn (Crawler $form): string => (string) $form->attr('action')) as $action) {
            $this->client->request('POST', $action, ['_token' => $this->token($crawler)]);
            self::assertResponseRedirects('/documents/'.$document->id());
        }

        $crawler = $this->client->request('GET', '/documents/'.$document->id());
        self::assertSame('Completed', trim($crawler->filter('[data-document-status]')->text()));
        self::assertCount(2, $crawler->filter('[data-signer-status="signed"]'));
        self::assertCount(1, $crawler->filter('header form[data-document-again]'));
        self::assertCount(0, $crawler->filter('header form[data-document-cancel]'));
    }

    public function testCancelAsksAndGenerateAgainMakesANewDraft(): void
    {
        $this->signedIn();
        [$nda, $template] = $this->ndaWithTemplate('<h1>Mutual NDA</h1>');
        $document = $this->document($nda, $template, 'NDA with Example Ltd');

        $crawler = $this->client->request('GET', '/documents/'.$document->id());
        $cancel = $crawler->filter('header form[data-document-cancel]');
        self::assertSame('confirm-submit', $cancel->attr('data-controller'));
        $this->post('/documents/'.$document->id().'/cancel', $crawler);
        $crawler = $this->client->followRedirect();
        self::assertSame('Cancelled', trim($crawler->filter('[data-document-status]')->text()));

        $this->post('/documents/'.$document->id().'/again', $crawler);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertNotSame('/documents/'.$document->id(), $location);
        $crawler = $this->client->followRedirect();
        self::assertSame('Draft', trim($crawler->filter('[data-document-status]')->text()));
    }

    public function testTheNewDocumentDialogTakesSeveralLinksFromTheAddress(): void
    {
        $contacts = new FakeLinkTarget();
        $client = Id::generate();
        $person = Id::generate();
        $contacts->add($client, 'Example Client Ltd');
        $contacts->add($person, 'Jane Example');
        self::getContainer()->set(LinkTargets::class, new LinkTargets([$contacts]));
        $this->signedIn();
        [$nda, $template] = $this->ndaWithTemplate('<h1>Mutual NDA</h1>');

        // A malformed entry and a repeat of the first link are left out.
        $url = '/documents/new?type='.$nda->id().'&link_kind=contact&link_id='.$client.'&links[]=contact:'.$person.'&links[]=Contact:not-an-id&links[]=contact:'.$client;
        $crawler = $this->client->request('GET', $url, server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Example Client Ltd', 'Jane Example'], $crawler->filter('#document-form [data-document-link] [data-document-link-label]')->each(static fn (Crawler $node): string => $node->text()));
        self::assertSame(['contact:'.$person], $crawler->filter('#document-form input[name="links[]"]')->each(static fn (Crawler $node): string => (string) $node->attr('value')));
        $values = $crawler->filter('#document-form')->form()->getPhpValues();

        $this->client->request('POST', '/documents/new', ['templateId' => $template->id(), 'title' => 'NDA with Example Client Ltd'] + $values, server: [$this->dialogHeader() => '1']);
        $document = $this->em()->getRepository(Document::class)->findOneBy(['title' => 'NDA with Example Client Ltd']);
        self::assertInstanceOf(Document::class, $document);
        $links = $this->em()->getRepository(DocumentLink::class)->findBy(['document' => $document]);
        $pairs = array_map(static fn (DocumentLink $link): string => $link->kind().':'.$link->targetId(), $links);
        sort($pairs);
        $expected = ['contact:'.$client, 'contact:'.$person];
        sort($expected);
        self::assertSame($expected, $pairs);
    }

    public function testALinkToARecordThatIsGoneShowsAsUnavailable(): void
    {
        $this->signedIn();
        [$nda, $template] = $this->ndaWithTemplate('<h1>Mutual NDA</h1>');
        $document = $this->document($nda, $template, 'NDA with Example Ltd', Id::generate());

        $crawler = $this->client->request('GET', '/documents/'.$document->id());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-document-links] [data-document-link-unavailable]'));
    }

    public function testTheRecordFragmentListsTheDocumentsOfARecordInsideAFrame(): void
    {
        $this->signedIn();
        [$nda, $template] = $this->ndaWithTemplate('<h1>Mutual NDA</h1>');
        $contactId = Id::generate();
        $this->document($nda, $template, 'NDA with Example Ltd', $contactId);
        $this->document($nda, $template, 'NDA with Another Ltd', Id::generate());
        $url = self::getContainer()->get('router')->generate(RecordDocuments::ROUTE, ['kind' => 'contact', 'id' => $contactId]);
        self::assertSame('/documents/for/contact/'.$contactId, $url);

        // Loaded by a Turbo frame of the contact: the answer is that frame alone, no page around it.
        $crawler = $this->client->request('GET', $url, server: ['HTTP_TURBO_FRAME' => 'contact-tab']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#contact-tab [data-record-documents]'));
        self::assertCount(0, $crawler->filter('#sidebar'));
        self::assertCount(1, $crawler->filter('[data-record-documents] tbody tr'));
        self::assertSelectorTextContains('[data-record-documents]', 'NDA with Example Ltd');
        self::assertSelectorTextContains('[data-record-documents]', 'Draft');
        $new = $crawler->filter('[data-record-documents] a[data-document-new]');
        self::assertSame('/documents/new?link_kind=contact&link_id='.$contactId, $new->attr('href'));
        self::assertNotNull($new->attr('data-dialog'));

        // The same address is a page of its own.
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#sidebar'));
        self::assertCount(1, $crawler->filter('[data-record-documents]'));
    }

    private function signedIn(): void
    {
        $this->user = $this->browser->seed();
        $this->browser->signIn();
    }

    /**
     * @return array{DocumentType, DocumentTemplate}
     */
    private function ndaWithTemplate(string $body): array
    {
        $type = $this->type('NDA', 'nda');

        return [$type, $this->template($type, 'NDA, English', $body)];
    }

    private function type(string $name, string $code): DocumentType
    {
        $type = new DocumentType($name, $code, true, SignatureLevel::Simple, new \DateTimeImmutable());
        $this->em()->persist($type);
        $this->em()->flush();

        return $type;
    }

    private function template(DocumentType $type, string $name, string $body): DocumentTemplate
    {
        $template = new DocumentTemplate($type, $name, new \DateTimeImmutable());
        $this->em()->persist($template);
        $this->em()->persist(new TemplateVersion($template, 1, $body, new PageSettings(), new \DateTimeImmutable()));
        $this->em()->flush();

        return $template;
    }

    private function document(DocumentType $type, DocumentTemplate $template, string $title, ?string $contactId = null): Document
    {
        $document = new Document($type, $template, $title, $this->user->id(), new \DateTimeImmutable());
        $this->em()->persist($document);
        if (null !== $contactId) {
            $this->em()->persist(new DocumentLink($document, 'contact', $contactId));
        }
        $this->em()->flush();

        return $document;
    }

    private function em(): EntityManagerInterface
    {
        return $this->browser->em();
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('header input[name="_token"]')->first()->attr('value');
    }

    private function post(string $url, Crawler $page): void
    {
        $this->client->request('POST', $url, ['_token' => $this->token($page)]);
    }

    private function location(): string
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, (string) $this->client->getResponse()->getContent());
        self::assertIsString($decoded['location'] ?? null);

        return $decoded['location'];
    }

    private function dialogHeader(): string
    {
        return 'HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER));
    }

    /**
     * @return array<mixed>
     */
    private function dialogForm(string $url, string $form): array
    {
        $crawler = $this->client->request('GET', $url, server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();

        return $crawler->filter($form)->form()->getPhpValues();
    }

    /**
     * @param array<mixed> $values
     */
    private function dialogPost(string $url, array $values): Crawler
    {
        return $this->client->request('POST', $url, $values, server: [$this->dialogHeader() => '1']);
    }

    private function file(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'signed');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }
}
