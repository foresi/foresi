<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Application\GenerateAddress;
use App\Banking\Application\ReceiveInboundMail;
use App\Tenancy\Application\TenantContext;
use App\Tests\Banking\Support\InboundMailKit;
use App\Tests\Banking\Support\Scalars;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The optional webhook takes raw mail from a relay such as an e-mail worker.
 * It is off without a secret, answers stored, duplicate and unknown recipient
 * alike so it tells nothing about which addresses exist, and is rate-limited.
 */
final class InboundMailControllerTest extends WebTestCase
{
    private const string URL = '/inbound/mail';
    private const string SECRET = 'test-inbound-secret';

    private KernelBrowser $client;
    private InboundMailKit $kit;
    /** @var array<string, string> environment variables a test changed, with their old values */
    private array $changedEnv = [];

    protected function setUp(): void
    {
        $this->boot();
    }

    protected function tearDown(): void
    {
        foreach ($this->changedEnv as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        parent::tearDown();
    }

    public function testWebhookWithoutSecretIs404(): void
    {
        $this->changeEnv('INBOUND_WEBHOOK_SECRET', '');

        $this->post(InboundMailKit::mail($this->kit->address));
        self::assertResponseStatusCodeSame(404);
        $this->post(InboundMailKit::mail($this->kit->address), bearer: '');
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, $this->rowCount());
    }

    public function testWrongBearerIs401(): void
    {
        foreach (['Bearer wrong-secret', 'Bearer '.self::SECRET.'x', 'Basic '.base64_encode('a:'.self::SECRET), self::SECRET, ''] as $authorization) {
            $this->client->request('POST', self::URL, server: ['CONTENT_TYPE' => 'message/rfc822'] + ('' === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization]), content: InboundMailKit::mail($this->kit->address));
            self::assertResponseStatusCodeSame(401, $authorization);
            self::assertResponseHasHeader('WWW-Authenticate');
        }

        self::assertSame(0, $this->rowCount());
    }

    public function testWebhookAnswers202ForStoredDuplicateAndUnknownAlike(): void
    {
        $answers = [];
        foreach ([InboundMailKit::mail($this->kit->address), InboundMailKit::mail($this->kit->address), InboundMailKit::mail($this->kit->unknownAddress(), 'unknown@mail.example.com')] as $raw) {
            $this->post($raw);
            self::assertResponseStatusCodeSame(202);
            $answers[] = (string) $this->client->getResponse()->getContent();
        }

        self::assertCount(1, array_unique($answers));
        self::assertSame(1, $this->rowCount());
        self::assertSame('webhook', $this->connection()->fetchOne('SELECT channel FROM inbound_message'));
        self::assertFalse(self::getContainer()->get(TenantContext::class)->has());
    }

    public function testTheRecipientHeaderRoutesTheMessage(): void
    {
        $this->post(InboundMailKit::mail('accounts@firm.example.com'), recipient: $this->kit->address);

        self::assertResponseStatusCodeSame(202);
        self::assertSame($this->kit->feedId, $this->connection()->fetchOne('SELECT bank_feed_id FROM inbound_message'));
    }

    public function testWebhookIsRateLimited(): void
    {
        self::getContainer()->get('test.inbound_mail_limiter')->create('127.0.0.1')->consume(120);

        $this->post(InboundMailKit::mail($this->kit->address));

        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, $this->rowCount());
    }

    public function testWebhookAbove10MiBIs413(): void
    {
        $this->post(str_repeat('a', ReceiveInboundMail::MAX_BYTES + 1));
        self::assertResponseStatusCodeSame(413);

        // A relay that states the length is refused before the body is read.
        $this->client->request('POST', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::SECRET, 'CONTENT_LENGTH' => (string) (ReceiveInboundMail::MAX_BYTES + 1)], content: 'Subject: small');
        self::assertResponseStatusCodeSame(413);

        self::assertSame(0, $this->rowCount());
    }

    public function testUnreadableMailIs400(): void
    {
        $this->post("not a mail\r\n");

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->rowCount());
    }

    public function testOnlyPostIsAccepted(): void
    {
        $this->client->request('GET', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::SECRET]);

        self::assertResponseStatusCodeSame(405);
    }

    public function testTheWebhookStartsNoSession(): void
    {
        $this->post(InboundMailKit::mail($this->kit->address));

        self::assertResponseStatusCodeSame(202);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    private function post(string $raw, ?string $bearer = self::SECRET, ?string $recipient = null): void
    {
        $server = ['CONTENT_TYPE' => 'message/rfc822'];
        if (null !== $bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        if (null !== $recipient) {
            $server['HTTP_X_FORESI_RECIPIENT'] = $recipient;
        }
        $this->client->request('POST', self::URL, server: $server, content: $raw);
    }

    private function boot(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->kit = InboundMailKit::seed(self::getContainer()->get(EntityManagerInterface::class), self::getContainer()->get(TenantContext::class), self::getContainer()->get(GenerateAddress::class));
    }

    private function changeEnv(string $name, string $value): void
    {
        $this->changedEnv[$name] ??= \is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : '';
        $_SERVER[$name] = $_ENV[$name] = $value;
        self::ensureKernelShutdown();
        $this->boot();
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function rowCount(): int
    {
        return Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM inbound_message'));
    }
}
