<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\AiReader\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Foresi\Purchasing\Infrastructure\Messenger\ReadReceivedDocumentHandler;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Support\IsdocFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What the AI reader shows in the settings of received documents and in the menu of a draft: whether it is set
 * up, with which provider, model and trigger, and Read with AI only when a person has to ask for it.
 */
final class AiReaderStatusTest extends WebTestCase
{
    private const string KEY = 'sk-test-not-a-real-key';
    private const string SETTINGS = '/settings/received-documents';
    private const array NAMES = ['AI_READER_PROVIDER', 'AI_READER_API_KEY', 'AI_READER_MODEL', 'AI_READER_TRIGGER'];

    private KernelBrowser $client;
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        foreach (self::NAMES as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        parent::tearDown();
    }

    public function testStatusWithoutKey(): void
    {
        $this->start([]);

        $page = $this->page(self::SETTINGS);

        $status = $page->filter('[data-ai-reader-status="not_configured"]');
        self::assertCount(1, $status);
        self::assertStringContainsString('Reading by AI is not set up', $status->text());
        self::assertSame('https://github.com/foresi/foresi/blob/main/docs/ai-reader.md', $status->filter('a')->attr('href'));
        self::assertStringContainsString('sent to the provider', $status->text());
        self::assertCount(0, $page->filter('[data-ai-reader-status="configured"]'));
    }

    public function testStatusWithKeyShowsProviderAndTriggerNotKey(): void
    {
        $this->start(['AI_READER_PROVIDER' => 'claude', 'AI_READER_API_KEY' => self::KEY, 'AI_READER_MODEL' => 'claude-example-model']);

        $page = $this->page(self::SETTINGS);

        $status = $page->filter('[data-ai-reader-status="configured"]');
        self::assertCount(1, $status);
        self::assertStringContainsString('Reading by AI: Claude, model claude-example-model, runs on arrival when the other readers miss the total, issue date or supplier', $this->flat($status));
        self::assertStringContainsString('sent to the provider', $status->text());
        self::assertStringNotContainsString(self::KEY, (string) $this->client->getResponse()->getContent());

        $this->start(['AI_READER_PROVIDER' => 'claude', 'AI_READER_API_KEY' => self::KEY, 'AI_READER_TRIGGER' => 'click']);
        $page = $this->page(self::SETTINGS);

        $text = $this->flat($page->filter('[data-ai-reader-status="configured"]'));
        self::assertStringContainsString('Reading by AI: Claude, model claude-sonnet-5-5, runs only when you click Read with AI', $text);
        self::assertStringNotContainsString(self::KEY, (string) $this->client->getResponse()->getContent());
    }

    public function testAnInvalidTriggerLeavesTheReaderOffAndSaysSoWithoutTheValue(): void
    {
        $this->start(['AI_READER_PROVIDER' => 'claude', 'AI_READER_API_KEY' => self::KEY, 'AI_READER_TRIGGER' => 'sometimes']);

        $page = $this->page(self::SETTINGS);

        self::assertCount(1, $page->filter('[data-ai-reader-status="not_configured"]'));
        self::assertStringContainsString('AI_READER_TRIGGER is not valid: use arrival or click', $this->flat($page->filter('[data-ai-reader-trigger-invalid]')));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('sometimes', $content);
        self::assertStringNotContainsString(self::KEY, $content);

        $id = $this->draft();
        $this->readQueued();
        self::assertCount(0, $this->page('/purchasing/'.$id)->filter('[data-ai-reader-read]'));
    }

    public function testReadWithAiActionOnlyOnClickMode(): void
    {
        $this->start(['AI_READER_PROVIDER' => 'claude', 'AI_READER_API_KEY' => self::KEY, 'AI_READER_TRIGGER' => 'click']);
        $id = $this->draft();
        $this->readQueued();

        $page = $this->page('/purchasing/'.$id);

        $form = $page->filter('[data-page-actions] form[data-ai-reader-read]');
        self::assertCount(1, $form);
        self::assertSame('/purchasing/'.$id.'/read-again', $form->attr('action'));
        self::assertStringContainsString('Read with AI', $form->text());
        // It posts to the route of Read again, with the CSRF token of the page.
        $this->client->submit($form->form());
        self::assertResponseRedirects('/purchasing/'.$id, 303);
        $this->client->followRedirect();
        self::assertStringContainsString('The file is being read again', (string) $this->client->getResponse()->getContent());

        // On arrival, the reader has already run on what it had to: no extra item.
        $this->start(['AI_READER_PROVIDER' => 'claude', 'AI_READER_API_KEY' => self::KEY, 'AI_READER_TRIGGER' => 'arrival']);
        $id = $this->draft();
        $this->readQueued();
        self::assertCount(0, $this->page('/purchasing/'.$id)->filter('[data-ai-reader-read]'));

        // Without a key there is nothing to read with.
        $this->start(['AI_READER_TRIGGER' => 'click']);
        $id = $this->draft();
        $this->readQueued();
        self::assertCount(0, $this->page('/purchasing/'.$id)->filter('[data-ai-reader-read]'));
    }

    /**
     * @param array<string, string> $env
     */
    private function start(array $env): void
    {
        foreach (self::NAMES as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        foreach ($env as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $rates = new FakeRateSource();
        $rates->publish('2026-10-28', '24.310', '15.120');
        self::getContainer()->set(RateSource::class, $rates);
        $browser = new SignedInBrowser($this->client, $this->em());
        $browser->seed();
        $this->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, ''));
        $this->em()->flush();
        $browser->signIn();
    }

    private function draft(): string
    {
        $this->client->request('GET', '/purchasing/upload');
        self::assertResponseIsSuccessful();
        $token = $this->client->getCrawler()->filter('main form[action^="/purchasing"] input[name="_token"]')->first()->attr('value');
        $path = tempnam(sys_get_temp_dir(), 'received');
        self::assertIsString($path);
        file_put_contents($path, IsdocFixtures::invoice());
        $this->paths[] = $path;
        $this->client->request('POST', '/purchasing/upload', ['_token' => $token], ['file' => new UploadedFile($path, 'invoice.isdoc', null, null, true)], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(201);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['id']);

        return $decoded['id'];
    }

    /**
     * Runs the readings the last request queued; an ISDOC file has what the structured reader needs, so
     * no AI request is ever made by this test.
     */
    private function readQueued(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ReadReceivedDocument) {
                self::getContainer()->get(ReadReceivedDocumentHandler::class)($message);
            }
        }
        $this->em()->clear();
    }

    private function page(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler;
    }

    private function flat(Crawler $crawler): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $crawler->text()));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
