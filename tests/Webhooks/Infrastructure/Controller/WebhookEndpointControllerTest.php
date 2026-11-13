<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Controller;

use App\Identity\Application\UserRepository;
use App\Identity\Domain\Role;
use App\Invoicing\Infrastructure\Events\InvoicingEventAccess;
use App\Shared\Application\Event\EventReadAccess;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Webhooks\Support\DeliveryRows;
use App\Webhooks\Domain\DisabledReason;
use App\Webhooks\Domain\EndpointUrl;
use App\Webhooks\Domain\EventTypes;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Domain\WebhookSecret;
use App\Webhooks\Infrastructure\Crypto\SodiumWebhookSecrets;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Settings › Webhooks: the endpoints of the organisation in a list, each with
 * its deliveries, created and changed in dialogs, with the actions in the page
 * header and the row menu. The secret shows only in the answers that hand it
 * over.
 */
final class WebhookEndpointControllerTest extends WebTestCase
{
    private const string TOKEN_ID = 'webhooks';
    private const string DIALOG = 'HTTP_X_FORESI_DIALOG';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private SignedInBrowser $browser;
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em, $this->context);
        $this->webhooks()->reset();
    }

    public function testListShowsEndpointsAndNewButtonInHeader(): void
    {
        $this->signIn();
        $id = $this->stored('Accounting');

        $crawler = $this->client->request('GET', '/settings/webhooks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Webhooks');
        $new = $crawler->filter('[data-page-actions] #webhook-new');
        self::assertCount(1, $new);
        self::assertSame('/settings/webhooks/new', $new->attr('href'));
        self::assertNotNull($new->attr('data-dialog'));
        self::assertContains('/settings/webhooks', $crawler->filter('#sidebar a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));

        $row = $crawler->filter('[data-webhook-endpoints] tbody tr');
        self::assertCount(1, $row);
        self::assertStringContainsString('https://hooks.example.com/stored', $row->text());
        self::assertStringContainsString('Accounting', $row->text());
        self::assertStringContainsString('invoicing.*', $row->text());
        self::assertStringContainsString('Active', $row->text());
        self::assertCount(1, $row->filter('a[href="/settings/webhooks/'.$id.'"]'));
        self::assertCount(1, $row->filter('a[href="/settings/webhooks/'.$id.'/edit"][data-dialog]'));
        self::assertCount(1, $row->filter('form[action="/settings/webhooks/'.$id.'/ping"]'));
        self::assertCount(1, $row->filter('form[action="/settings/webhooks/'.$id.'/disable"]'));
        $delete = $row->filter('form[action="/settings/webhooks/'.$id.'/delete"]');
        self::assertCount(1, $delete);
        self::assertSame('danger', $delete->attr('data-confirm-tone'));
        self::assertNotNull($delete->attr('data-turbo-confirm'));
        self::assertStringNotContainsString('whsec_', (string) $this->client->getResponse()->getContent());
    }

    public function testEmptyListAsksToAddAnEndpoint(): void
    {
        $this->signIn();

        $this->client->request('GET', '/settings/webhooks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No webhook endpoints');
    }

    public function testCreateDialogShowsSecretOnce(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/settings/webhooks/new', server: [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<dialog', (string) $this->client->getResponse()->getContent());
        $options = $crawler->filter('input[name="event_types[]"]')->each(static fn (Crawler $box): string => (string) $box->attr('value'));
        self::assertContains('invoicing.*', $options);
        self::assertContains('invoicing.invoice.paid', $options);

        $this->client->request('POST', '/settings/webhooks/new', [
            '_token' => $this->csrf(),
            'url' => 'https://hooks.example.com/foresi',
            'description' => 'Accounting',
            'event_types' => ['invoicing.*'],
            'version' => '1',
        ], server: [self::DIALOG => '1']);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $page = $this->client->getCrawler();
        self::assertCount(1, $page->filter('dialog#dialog-page[data-dialog-reload="1"]'));
        $secret = (string) $page->filter('input#webhook-secret')->attr('value');
        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9+\/]+={0,2}$/', $secret);
        self::assertCount(1, $page->filter('[data-webhook-secret-once]'));
        self::assertCount(1, $this->webhooks()->getSent(), 'The new endpoint is pinged.');

        $endpoints = $this->endpoints();
        self::assertCount(1, $endpoints);
        self::assertSame('Accounting', $endpoints[0]->description());

        // Neither the list nor the detail shows it again.
        $this->client->request('GET', '/settings/webhooks');
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/settings/webhooks/'.$endpoints[0]->id());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('whsec_', (string) $this->client->getResponse()->getContent());
    }

    public function testCreateRefusesAnInvalidUrlWithTheFormAgain(): void
    {
        $this->signIn();

        $this->client->request('POST', '/settings/webhooks/new', [
            '_token' => $this->csrf(),
            'url' => 'http://hooks.example.com/foresi',
            'event_types' => ['invoicing.*'],
            'version' => '1',
        ], server: [self::DIALOG => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#url-error', 'https');
        self::assertSame('http://hooks.example.com/foresi', $this->client->getCrawler()->filter('input[name="url"]')->attr('value'));
        self::assertSame([], $this->endpoints());

        $this->client->request('POST', '/settings/webhooks/new', [
            '_token' => $this->csrf(),
            'url' => 'https://hooks.example.com/foresi',
            'version' => '1',
        ], server: [self::DIALOG => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#event-types-invoicing-error');
        self::assertSame([], $this->endpoints());
    }

    public function testNonStaffSeesNoInvoicingOption(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/');
        $crawler = $this->client->getResponse()->isRedirection() ? $this->client->followRedirect() : $this->client->getCrawler();
        self::assertNotContains('/settings/webhooks', $crawler->filter('#sidebar a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));

        foreach (['/settings/webhooks', '/settings/webhooks/new'] as $url) {
            $this->client->request('GET', $url, server: [self::DIALOG => '1']);
            self::assertResponseStatusCodeSame(403, $url);
            self::assertCount(0, $this->client->getCrawler()->filter('input[name="event_types[]"]'), $url);
        }

        $this->client->request('POST', '/settings/webhooks/new', ['_token' => 'x', 'url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*'], 'version' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->endpoints());
    }

    public function testWithoutAReadableModuleThereIsNoPage(): void
    {
        $this->signIn();
        $id = $this->stored();
        // The person no longer works with invoices: the module's access service says no.
        self::getContainer()->set(InvoicingEventAccess::class, new class implements EventReadAccess {
            public function module(): string
            {
                return 'invoicing';
            }

            public function mayRead(): bool
            {
                return false;
            }

            public function mayUserRead(string $userId): bool
            {
                return false;
            }
        });

        $crawler = $this->client->request('GET', '/');
        self::assertNotContains('/settings/webhooks', $crawler->filter('#sidebar a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        foreach (['/settings/webhooks', '/settings/webhooks/new', '/settings/webhooks/'.$id] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testDetailShowsDeliveriesWithRedeliverInRowMenu(): void
    {
        $this->signIn();
        $id = $this->stored();
        $rows = new DeliveryRows($this->em->getConnection());
        $failed = $rows->insert($this->tenant->toString(), $id, T::STATUS_FAILED, 10, null);
        $rows->attempt($this->tenant->toString(), $failed, '2026-11-13 10:00:00');
        $pending = $rows->insert($this->tenant->toString(), $id);
        $ping = $rows->insert($this->tenant->toString(), $id, T::STATUS_SUCCEEDED, 1, null, T::KIND_PING);

        $crawler = $this->client->request('GET', '/settings/webhooks/'.$id);

        self::assertResponseIsSuccessful();
        $header = $crawler->filter('[data-page-actions]');
        self::assertCount(1, $header->filter('a[href="/settings/webhooks/'.$id.'/edit"][data-dialog]'));
        self::assertCount(1, $header->filter('form[action="/settings/webhooks/'.$id.'/ping"]'));
        self::assertCount(1, $header->filter('a[href="/settings/webhooks/'.$id.'/rotate"][data-dialog]'));
        self::assertCount(1, $header->filter('a[href="/settings/webhooks/'.$id.'/secret"][data-dialog]'));
        self::assertCount(1, $header->filter('form[action="/settings/webhooks/'.$id.'/disable"]'));
        self::assertSame('danger', $header->filter('form[action="/settings/webhooks/'.$id.'/delete"]')->attr('data-confirm-tone'));

        $table = $crawler->filter('[data-webhook-deliveries] tbody tr');
        self::assertCount(3, $table);
        $redeliver = '/settings/webhooks/'.$id.'/deliveries/%s/redeliver';
        self::assertCount(1, $crawler->filter(\sprintf('[data-webhook-deliveries] form[action="'.$redeliver.'"]', $failed)));
        self::assertCount(0, $crawler->filter(\sprintf('[data-webhook-deliveries] form[action="'.$redeliver.'"]', $pending)));
        self::assertCount(0, $crawler->filter(\sprintf('[data-webhook-deliveries] form[action="'.$redeliver.'"]', $ping)));
        self::assertCount(1, $crawler->filter('[data-webhook-deliveries] a[href="/settings/webhooks/'.$id.'/deliveries/'.$failed.'"][data-dialog]'));

        $this->client->submit($crawler->filter(\sprintf('form[action="'.$redeliver.'"]', $failed))->form());

        self::assertResponseRedirects('/settings/webhooks/'.$id);
        self::assertCount(1, $this->webhooks()->getSent());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The delivery is queued again.');

        $attempts = $this->client->request('GET', '/settings/webhooks/'.$id.'/deliveries/'.$failed, server: [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('500', $attempts->filter('[data-webhook-attempts]')->text());
    }

    public function testDeleteNeedsCsrf(): void
    {
        $this->signIn();
        $id = $this->stored();

        $this->client->request('POST', '/settings/webhooks/'.$id.'/delete', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/settings/webhooks/'.$id.'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->endpoints());

        $this->client->request('POST', '/settings/webhooks/'.$id.'/delete', ['_token' => $this->csrf()]);
        self::assertResponseRedirects('/settings/webhooks');
        self::assertSame([], $this->endpoints());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The webhook endpoint is deleted.');
    }

    public function testDisabledEndpointShowsReason(): void
    {
        $this->signIn();
        $id = $this->stored(disabled: DisabledReason::Gone);

        $crawler = $this->client->request('GET', '/settings/webhooks/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-webhook-disabled]', '410 Gone');
        $header = $crawler->filter('[data-page-actions]');
        self::assertCount(1, $header->filter('form[action="/settings/webhooks/'.$id.'/enable"]'));
        self::assertCount(0, $header->filter('form[action="/settings/webhooks/'.$id.'/disable"]'));
        self::assertCount(0, $header->filter('form[action="/settings/webhooks/'.$id.'/ping"]'));

        $list = $this->client->request('GET', '/settings/webhooks');
        self::assertStringContainsString('Disabled', $list->filter('[data-webhook-endpoints] tbody tr')->text());

        $this->client->submit($header->filter('form[action="/settings/webhooks/'.$id.'/enable"]')->form());
        self::assertResponseRedirects('/settings/webhooks/'.$id);
        self::assertTrue($this->endpoints()[0]->isActive());
    }

    public function testEditDialogChangesTheEndpoint(): void
    {
        $this->signIn();
        $id = $this->stored('Accounting');

        $crawler = $this->client->request('GET', '/settings/webhooks/'.$id.'/edit', server: [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame('https://hooks.example.com/stored', $crawler->filter('input[name="url"]')->attr('value'));
        self::assertSame('invoicing.*', $crawler->filter('input[name="event_types[]"][checked]')->attr('value'));

        $this->client->request('POST', '/settings/webhooks/'.$id.'/edit', [
            '_token' => $this->csrf(),
            'url' => 'https://hooks.example.com/changed',
            'description' => '',
            'event_types' => ['invoicing.invoice.paid', 'invoicing.invoice.issued'],
            'version' => '1',
        ]);

        self::assertResponseRedirects('/settings/webhooks/'.$id);
        $endpoint = $this->endpoints()[0];
        self::assertSame('https://hooks.example.com/changed', $endpoint->url()->value);
        self::assertNull($endpoint->description());
        self::assertEqualsCanonicalizing(['invoicing.invoice.paid', 'invoicing.invoice.issued'], $endpoint->eventTypes()->patterns());
    }

    public function testRotateAndRevealShowTheSecretInADialog(): void
    {
        $this->signIn();
        $this->client->request('POST', '/settings/webhooks/new', ['_token' => $this->csrf(), 'url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*'], 'version' => '1']);
        $first = (string) $this->client->getCrawler()->filter('input#webhook-secret')->attr('value');
        $id = $this->endpoints()[0]->id();

        $crawler = $this->client->request('GET', '/settings/webhooks/'.$id.'/rotate', server: [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('whsec_', (string) $this->client->getResponse()->getContent());
        self::assertSame('24', $crawler->filter('select[name="keep_previous_hours"] option[selected]')->attr('value'));

        $this->client->request('POST', '/settings/webhooks/'.$id.'/rotate', ['_token' => 'forged', 'keep_previous_hours' => '24'], server: [self::DIALOG => '1']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/settings/webhooks/'.$id.'/rotate', ['_token' => $this->csrf(), 'keep_previous_hours' => '24'], server: [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $rotated = (string) $this->client->getCrawler()->filter('input#webhook-secret')->attr('value');
        self::assertStringStartsWith('whsec_', $rotated);
        self::assertNotSame($first, $rotated);

        $this->client->request('GET', '/settings/webhooks/'.$id.'/secret', server: [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('whsec_', (string) $this->client->getResponse()->getContent(), 'Opening the dialog reveals nothing yet.');

        $this->client->request('POST', '/settings/webhooks/'.$id.'/secret', ['_token' => $this->csrf()], server: [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame($rotated, $this->client->getCrawler()->filter('input#webhook-secret')->attr('value'));
    }

    public function testPingAndDisableFromTheHeader(): void
    {
        $this->signIn();
        $id = $this->stored();

        $this->client->request('POST', '/settings/webhooks/'.$id.'/ping', ['_token' => $this->csrf()]);
        self::assertResponseRedirects('/settings/webhooks/'.$id);
        self::assertCount(1, $this->webhooks()->getSent());
        self::assertSame(1, $this->pings());

        $this->client->request('POST', '/settings/webhooks/'.$id.'/disable', ['_token' => $this->csrf()]);
        self::assertResponseRedirects('/settings/webhooks/'.$id);
        self::assertFalse($this->endpoints()[0]->isActive());
        self::assertSame(DisabledReason::Manual, $this->endpoints()[0]->disabledReason());

        $this->client->request('POST', '/settings/webhooks/'.$id.'/ping', ['_token' => $this->csrf()]);
        self::assertResponseRedirects('/settings/webhooks/'.$id);
        self::assertSame(1, $this->pings(), 'A disabled endpoint is not pinged.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'disabled');
    }

    public function testAnEndpointOfAnotherOrganisationIsNotFound(): void
    {
        $this->signIn();
        $other = $this->browser->fixtures->tenant('Other Company');
        $foreign = $this->stored(tenant: $other);

        $this->client->request('GET', '/settings/webhooks/'.$foreign);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/settings/webhooks/'.$foreign.'/delete', ['_token' => $this->csrf()]);
        self::assertResponseStatusCodeSame(404);
    }

    private function signIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Example Company'], role: $role);
        $this->browser->signIn();
    }

    /**
     * An endpoint stored straight in the organisation, owned by the signed-in
     * person, with a secret sealed by a key of its own.
     */
    private function stored(?string $description = null, ?DisabledReason $disabled = null, ?TenantId $tenant = null): string
    {
        $owner = self::getContainer()->get(UserRepository::class)->findByEmail(IdentityFixtures::EMAIL) ?? throw new \LogicException('The user is missing.');
        $this->context->switchTo($tenant ?? $this->tenant);
        try {
            $secrets = new SodiumWebhookSecrets('stored:'.base64_encode(str_repeat("\x07", \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
            $now = new \DateTimeImmutable('2026-11-13 10:00:00');
            $endpoint = WebhookEndpoint::create(Id::generate(), new EndpointUrl('https://hooks.example.com/stored'), $description, EventTypes::of(['invoicing.*']), 1, $secrets->seal(WebhookSecret::generate()), $owner->id(), $now);
            if ($disabled instanceof DisabledReason) {
                $endpoint->disable($disabled, $now);
            }
            $this->em->persist($endpoint);
            $this->em->flush();

            return $endpoint->id();
        } finally {
            $this->context->reset();
        }
    }

    /**
     * @return list<WebhookEndpoint>
     */
    private function endpoints(): array
    {
        $this->em->clear();
        $this->context->switchTo($this->tenant);
        try {
            return $this->em->getRepository(WebhookEndpoint::class)->findAll();
        } finally {
            $this->context->reset();
        }
    }

    /**
     * The token of the session, which the list page puts in it.
     */
    private function csrf(): string
    {
        $this->client->request('GET', '/settings/webhooks');
        $stack = self::getContainer()->get('request_stack');
        $stack->push($this->client->getRequest());
        try {
            return self::getContainer()->get('security.csrf.token_manager')->getToken(self::TOKEN_ID)->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function pings(): int
    {
        $count = $this->em->getConnection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', T::DELIVERY, T::KIND), [T::KIND_PING]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function webhooks(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
