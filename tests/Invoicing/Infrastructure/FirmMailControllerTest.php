<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\MailSecrets;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Support\Mail\RecordingMailGateway;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class FirmMailControllerTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-battery';
    private const array SERVER = [
        'host' => 'smtp.example.com',
        'port' => '587',
        'encryption' => 'starttls',
        'username' => 'invoices@example.com',
        'password' => self::PASSWORD,
        'fromAddress' => 'invoices@example.com',
        'fromName' => 'Example Firm',
    ];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private ?string $keys = null;

    protected function setUp(): void
    {
        $this->boot();
        RecordingMailGateway::reset();
        Clock::set(new MockClock('2026-11-02 12:00:00'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        if (null !== $this->keys) {
            $_SERVER['MAIL_SECRET_KEYS'] = $_ENV['MAIL_SECRET_KEYS'] = $this->keys;
        }
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    public function testThePageHasBothTabsAndTheChips(): void
    {
        $firm = $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->url($firm));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main [data-action-bar]');
        self::assertCount(2, $crawler->filter('main nav[aria-label="E-mail settings sections"] a'));
        self::assertSame('page', $crawler->filter('main nav[aria-label="Firm sections"] a[href$="/mail"]')->attr('aria-current'));
        self::assertSame('Invoice {number}', $crawler->filter('#subject-en')->attr('value'));
        self::assertStringContainsString('{amount_total}', $crawler->filter('#body-en')->text());
        $chips = $crawler->filter('[role="group"][aria-labelledby] button[type="button"][data-token]');
        self::assertSame(['{number}', '{link}'], [$chips->first()->attr('data-token'), $chips->eq(19)->attr('data-token')]);
    }

    public function testSavingStoresServerAndTemplate(): void
    {
        $firm = $this->signedInFirm();

        $this->saveServer($firm, self::SERVER);
        self::assertResponseRedirects($this->url($firm, 'server'));
        $this->saveTemplates($firm, ['subject' => ['cs' => 'Faktura {number} od nás'], 'body' => ['cs' => "Dobrý den,\n{link}"]]);
        self::assertResponseRedirects($this->url($firm));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The e-mail templates are saved.');

        $server = $this->connection()->fetchAssociative('SELECT legal_entity_id, host, port, encryption, username, from_address, from_name, verified_at FROM firm_mail_server');
        self::assertSame(['legal_entity_id' => $firm->id(), 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'invoices@example.com', 'from_address' => 'invoices@example.com', 'from_name' => 'Example Firm', 'verified_at' => null], $server);
        self::assertSame(self::PASSWORD, $this->storedPassword());
        // The English text was left as built in, so only the Czech one is stored.
        self::assertSame([['language' => 'cs', 'subject' => 'Faktura {number} od nás', 'body' => "Dobrý den,\n{link}"]], $this->connection()->fetchAllAssociative('SELECT language, subject, body FROM mail_template'));
    }

    public function testResetGivesTheBuiltInTextBack(): void
    {
        $firm = $this->signedInFirm();
        $this->saveTemplates($firm, ['subject' => ['en' => 'Own {number}']]);

        $this->saveTemplates($firm, ['reset' => 'en']);

        self::assertResponseRedirects($this->url($firm));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testBlankPasswordKeepsTheStoredOne(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);

        $this->saveServer($firm, ['host' => 'mail.example.com', 'password' => ''] + self::SERVER);

        self::assertResponseRedirects($this->url($firm, 'server'));
        self::assertSame('mail.example.com', $this->connection()->fetchOne('SELECT host FROM firm_mail_server'));
        self::assertSame(self::PASSWORD, $this->storedPassword());
    }

    public function testAFirstServerNeedsAPassword(): void
    {
        $firm = $this->signedInFirm();

        $this->saveServer($firm, ['password' => ''] + self::SERVER);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#password-error');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testAPrivateHostIsRefusedAtSave(): void
    {
        $firm = $this->signedInFirm();

        foreach (['internal.example.com', '127.0.0.1', 'smtp.example.com:587'] as $host) {
            $this->saveServer($firm, ['host' => $host] + self::SERVER);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorExists('#host-error');
        }
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testThePasswordIsNeverInTheResponse(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, ['port' => '25'] + self::SERVER);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#port-error');
        $refused = (string) $this->client->getResponse()->getContent();
        $this->saveServer($firm, self::SERVER);

        $this->client->request('GET', $this->url($firm, 'server'));

        $page = (string) $this->client->getResponse()->getContent();
        self::assertSame('smtp.example.com', $this->client->getCrawler()->filter('#host')->attr('value'));
        self::assertSame('', $this->client->getCrawler()->filter('#password')->attr('value'));
        foreach ([$refused, $page] as $content) {
            self::assertStringNotContainsString(self::PASSWORD, $content);
            self::assertStringNotContainsString($this->sealed(), $content);
        }
    }

    public function testUnknownVariableIsRefusedAndNamed(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, ['subject' => ['cs' => 'Faktura {numbr}'], 'body' => ['en' => 'Pay {amount} now {link}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{numbr}');
        self::assertSelectorTextContains('#body-en-error', '{amount}');
        self::assertSame('Faktura {numbr}', $this->client->getCrawler()->filter('#subject-cs')->attr('value'), 'the typed text stays');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testTheLinkInTheSubjectIsRefused(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, ['subject' => ['cs' => 'Faktura {link}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{link}');
    }

    public function testAMissingKeyExplainsItself(): void
    {
        $this->keys = \is_string($_SERVER['MAIL_SECRET_KEYS'] ?? null) ? $_SERVER['MAIL_SECRET_KEYS'] : '';
        $_SERVER['MAIL_SECRET_KEYS'] = $_ENV['MAIL_SECRET_KEYS'] = '';
        self::ensureKernelShutdown();
        $this->boot();
        $firm = $this->signedInFirm();

        $this->client->request('GET', $this->url($firm, 'server'));
        self::assertSelectorTextContains('main [role="alert"], main .alert, main', 'MAIL_SECRET_KEYS');
        $this->saveServer($firm, self::SERVER);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'MAIL_SECRET_KEYS');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testAnotherTenantsFirmIs404(): void
    {
        $this->signedInFirm();
        $foreign = $this->firm($this->browser->fixtures->tenant('Foreign Tenant'));

        $this->client->request('GET', $this->url($foreign));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url($foreign), ['section' => 'server'] + self::SERVER);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url($foreign).'/test');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAClientRoleGets403(): void
    {
        $tenant = $this->browser->seed(['Tenant A'], role: Role::Client)[0];
        $firm = $this->firm($tenant);
        $this->browser->signIn();

        $this->client->request('GET', $this->url($firm));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url($firm).'/test');
        self::assertResponseStatusCodeSame(403);
    }

    public function testACsrfFailureChangesNothing(): void
    {
        $firm = $this->signedInFirm();

        $this->client->request('POST', $this->url($firm), ['_token' => 'wrong', 'section' => 'server'] + self::SERVER);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testTheTestMailGoesToTheUserAndSetsVerifiedAt(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);

        $this->sendTest($firm);

        self::assertResponseRedirects($this->url($firm, 'server'));
        $messages = $this->gateway()->messages();
        self::assertCount(1, $messages);
        self::assertSame([IdentityFixtures::EMAIL], $messages[0]->to);
        self::assertSame('invoices@example.com', $messages[0]->from);
        self::assertMatchesRegularExpression('/^<[^@]+@example\.com>$/', $messages[0]->messageId);
        self::assertSame('2026-11-02 12:00:00', $this->connection()->fetchOne('SELECT verified_at FROM firm_mail_server'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', IdentityFixtures::EMAIL);
    }

    public function testARefusedTestShowsTheSafeReason(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);
        $this->gateway()->failNext(1, true);

        $this->sendTest($firm);
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'SMTP 550: the mail server refused the message.');
        self::assertNull($this->connection()->fetchOne('SELECT verified_at FROM firm_mail_server'));
    }

    public function testATestWithoutAServerSendsNothing(): void
    {
        $firm = $this->signedInFirm();

        $this->client->request('GET', $this->url($firm, 'server'));
        self::assertSelectorNotExists('button[form="mail-test"]');
    }

    public function testTheTestIsRateLimited(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);

        for ($i = 0; $i < 6; ++$i) {
            $this->sendTest($firm);
        }
        $this->client->followRedirect();

        self::assertCount(5, $this->gateway()->messages());
        self::assertSelectorTextContains('main', 'Too many test e-mails');
    }

    private function boot(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
    }

    private function signedInFirm(): LegalEntity
    {
        [$tenant] = $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        return $this->firm($tenant);
    }

    private function firm(TenantId $tenant): LegalEntity
    {
        $this->context->switchTo($tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
        $em = $this->browser->em();
        $em->persist($firm);
        $em->flush();
        $this->context->reset();

        return $firm;
    }

    private function url(LegalEntity $firm, ?string $tab = null): string
    {
        return '/settings/firms/'.$firm->id().'/mail'.(null === $tab ? '' : '?tab='.$tab);
    }

    /**
     * @param array<string, string> $values
     */
    private function saveServer(LegalEntity $firm, array $values): void
    {
        $crawler = $this->client->request('GET', $this->url($firm, 'server'));
        $form = $crawler->filter('#mail-server')->form();
        $this->client->request('POST', $this->url($firm), $values + $form->getPhpValues());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function saveTemplates(LegalEntity $firm, array $values): void
    {
        $crawler = $this->client->request('GET', $this->url($firm));
        $this->client->request('POST', $this->url($firm), array_replace_recursive($crawler->filter('#mail-templates')->form()->getPhpValues(), $values));
    }

    private function sendTest(LegalEntity $firm): void
    {
        $crawler = $this->client->request('GET', $this->url($firm, 'server'));
        $this->client->request('POST', $this->url($firm).'/test', $crawler->filter('#mail-test')->form()->getPhpValues());
    }

    private function storedPassword(): string
    {
        return self::getContainer()->get(MailSecrets::class)->open($this->sealed());
    }

    private function gateway(): RecordingMailGateway
    {
        // Its record is static, so a new instance reads what the container's one kept.
        return new RecordingMailGateway();
    }

    private function sealed(): string
    {
        $sealed = $this->connection()->fetchOne('SELECT secret_sealed FROM firm_mail_server');
        self::assertIsString($sealed);

        return $sealed;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
