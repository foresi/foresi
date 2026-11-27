<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\PageSettings;
use Foresi\Documents\Domain\SignatureLevel;
use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Settings → Documents: the types in their dialog, archived after asking, and
 * a template made in a dialog and written in its editor, with the variables
 * beside the source, a preview and the version it saves to.
 */
final class DocumentSettingsScreensTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->browser->seed();
        $this->browser->signIn();
    }

    public function testTheOwnerAddsChangesAndArchivesATypeInItsDialog(): void
    {
        $crawler = $this->client->request('GET', '/settings/documents');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#sidebar a[href="/settings/documents"]'));
        self::assertCount(1, $crawler->filter('header a[data-type-new][data-dialog][href="/settings/documents/types/new"]'));
        // Without a type there is nothing to make a template of.
        self::assertCount(0, $crawler->filter('header a[data-template-new]'));

        $values = $this->dialogForm('/settings/documents/types/new', '#type-form');
        $this->dialogPost('/settings/documents/types/new', ['name' => 'Service contract', 'code' => 'service_contract', 'needsSigning' => '1', 'signatureLevel' => 'advanced'] + $values);
        self::assertSame('/settings/documents', $this->location());
        $crawler = $this->dialogPost('/settings/documents/types/new', ['name' => 'Another contract', 'code' => 'service_contract', 'signatureLevel' => 'simple'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#type-form [aria-invalid="true"]#code'));
        self::assertStringContainsString('code', $crawler->filter('#type-form')->text());

        $type = $this->type('service_contract');
        self::assertTrue($type->needsSigning());
        $crawler = $this->client->request('GET', '/settings/documents');
        self::assertSelectorTextContains('[data-document-types]', 'Service contract');
        self::assertSelectorTextContains('[data-document-types]', 'Advanced signature');
        self::assertCount(1, $crawler->filter('header a[data-template-new]'));

        // The code is set once: the change dialog shows it and keeps it.
        $values = $this->dialogForm('/settings/documents/types/'.$type->id().'/edit', '#type-form');
        self::assertArrayNotHasKey('code', $values);
        $this->dialogPost('/settings/documents/types/'.$type->id().'/edit', ['name' => 'Services contract', 'needsSigning' => '', 'signatureLevel' => 'simple'] + $values);
        $this->em()->clear();
        $type = $this->type('service_contract');
        self::assertSame('Services contract', $type->name());
        self::assertFalse($type->needsSigning());

        $this->client->request('POST', '/settings/documents/types/'.$type->id().'/archive', ['_token' => $this->token($crawler)]);
        self::assertResponseRedirects('/settings/documents');
        $this->em()->clear();
        self::assertTrue($this->type('service_contract')->isArchived());
    }

    public function testATemplateIsMadeInADialogAndWrittenInItsEditor(): void
    {
        $type = new DocumentType('NDA', 'nda', true, SignatureLevel::Simple, new \DateTimeImmutable());
        $this->em()->persist($type);
        $this->em()->flush();

        $values = $this->dialogForm('/settings/documents/templates/new', '#template-new-form');
        self::assertSame($type->id(), $values['typeId']);
        $this->dialogPost('/settings/documents/templates/new', ['name' => 'NDA, English'] + $values);
        $location = $this->location();
        $template = $this->em()->getRepository(DocumentTemplate::class)->findOneBy(['name' => 'NDA, English']);
        self::assertInstanceOf(DocumentTemplate::class, $template);
        self::assertSame('/settings/documents/templates/'.$template->id(), $location);

        $crawler = $this->client->request('GET', $location);
        self::assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter('[data-template-version]')->attr('data-template-version'));
        self::assertCount(0, $crawler->filter('[data-template-frozen="1"]'));
        self::assertCount(1, $crawler->filter('header button[data-template-save][form="template-form"]'));
        self::assertCount(1, $crawler->filter('#template-form textarea#body'));
        self::assertCount(1, $crawler->filter('[data-template-variables]'));
        self::assertStringContainsString('NDA, English', (string) $crawler->filter('iframe[data-template-preview-frame]')->attr('srcdoc'));
        self::assertSame('', $crawler->filter('iframe[data-template-preview-frame]')->attr('sandbox'));

        // Saved in place while no document was generated from it.
        $form = $crawler->filter('#template-form')->form();
        $this->client->submit($form, ['body' => '<h1>Mutual NDA</h1>', 'marginTop' => '25']);
        self::assertResponseRedirects($location);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-document-flash="success"]', 'version 1');
        self::assertSame('25', $crawler->filter('#marginTop')->attr('value'));

        // A margin past the page and a template outside the sandbox are refused at their field.
        $crawler = $this->client->submit($crawler->filter('#template-form')->form(), ['marginLeft' => '90']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#template-form [aria-invalid="true"]#marginLeft'));
        $crawler = $this->client->submit($crawler->filter('#template-form')->form(), ['marginLeft' => '20', 'body' => '{% include "x" %}']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#template-form [aria-invalid="true"]#body'));

        // Once a document is generated from it, the version is frozen and saving makes version 2.
        $version = $this->em()->getRepository(TemplateVersion::class)->findOneBy(['templateId' => $template->id()]);
        self::assertInstanceOf(TemplateVersion::class, $version);
        $version->freeze(new \DateTimeImmutable());
        $this->em()->flush();
        $crawler = $this->client->request('GET', $location);
        self::assertCount(1, $crawler->filter('[data-template-frozen="1"]'));
        self::assertStringContainsString('version 2', $crawler->filter('[data-template-version]')->text());
        $this->client->submit($crawler->filter('#template-form')->form(), ['body' => '<h1>Mutual NDA, 2</h1>']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-document-flash="success"]', 'version 2');
        self::assertSame('2', $crawler->filter('[data-template-version]')->attr('data-template-version'));
    }

    public function testThePreviewFillsTheSourceOrSaysWhyItIsRefused(): void
    {
        $type = new DocumentType('NDA', 'nda', true, SignatureLevel::Simple, new \DateTimeImmutable());
        $this->em()->persist($type);
        $template = new DocumentTemplate($type, 'NDA, English', new \DateTimeImmutable());
        $this->em()->persist($template);
        $this->em()->persist(new TemplateVersion($template, 1, '<p>NDA</p>', new PageSettings(), new \DateTimeImmutable()));
        $this->em()->flush();
        $crawler = $this->client->request('GET', '/settings/documents/templates/'.$template->id());
        $values = $crawler->filter('#template-form')->form()->getPhpValues();

        $this->client->request('POST', '/settings/documents/templates/'.$template->id().'/preview', ['body' => '<p>{{ "Example"|upper }}</p>'] + $values);
        $answer = $this->json();
        self::assertNull($answer['error']);
        self::assertIsString($answer['html']);
        self::assertStringContainsString('EXAMPLE', $answer['html']);
        self::assertStringContainsString('padding:20mm 20mm 20mm 20mm', $answer['html']);

        // A variable no installed module offers (Sales offers deal.*).
        $this->client->request('POST', '/settings/documents/templates/'.$template->id().'/preview', ['body' => '<p>{{ unknown.title }}</p>'] + $values);
        $answer = $this->json();
        self::assertNull($answer['html']);
        self::assertIsString($answer['error']);
        self::assertStringContainsString('unknown.title', $answer['error']);
    }

    private function type(string $code): DocumentType
    {
        $type = $this->em()->getRepository(DocumentType::class)->findOneBy(['code' => $code]);
        self::assertInstanceOf(DocumentType::class, $type);

        return $type;
    }

    private function em(): EntityManagerInterface
    {
        return $this->browser->em();
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('[data-document-types] tbody form input[name="_token"]')->first()->attr('value');
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        self::assertResponseIsSuccessful();
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
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
}
