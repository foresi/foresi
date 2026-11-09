<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Work\Domain\EmailDelivery;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\NotificationPreference;
use App\Work\Infrastructure\Mail\NotificationLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The signed link at the foot of a notification e-mail, opened without
 * signing in.
 */
final class UnsubscribeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em, $this->context);
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: Role::Member);
    }

    public function testOpeningTheLinkAsksFirstAndChangesNothing(): void
    {
        $link = $this->link('mentioned');

        $crawler = $this->client->request('GET', $link);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Stop e-mails');
        self::assertSame('Stop e-mails about mentions in comments?', trim($crawler->filter('[data-unsubscribe-question]')->text()));
        self::assertSame($link, $crawler->filter('form')->attr('action'));
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        self::assertNull($this->preference());
    }

    public function testTheButtonStopsThatKindOfEMailOnly(): void
    {
        $crawler = $this->client->request('GET', $this->link('mentioned'));

        $crawler = $this->client->submit($crawler->filter('form')->form());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'E-mails stopped');
        self::assertSame('No more e-mails about mentions.', trim($crawler->filter('[data-unsubscribe-done]')->text()));
        $preference = $this->preference();
        self::assertInstanceOf(NotificationPreference::class, $preference);
        self::assertSame(EmailDelivery::Off, $preference->delivery(NotificationKind::Mentioned));
        self::assertSame(EmailDelivery::Immediate, $preference->delivery(NotificationKind::Assigned));
        self::assertSame(EmailDelivery::Digest, $preference->delivery(NotificationKind::Commented));
    }

    public function testAMailClientUnsubscribesInOneClickWithoutASession(): void
    {
        $this->client->request('POST', $this->link('digest'), ['List-Unsubscribe' => 'One-Click']);

        self::assertResponseIsSuccessful();
        $preference = $this->preference();
        self::assertInstanceOf(NotificationPreference::class, $preference);
        self::assertSame(EmailDelivery::Immediate, $preference->delivery(NotificationKind::Mentioned));
        self::assertSame(EmailDelivery::Off, $preference->delivery(NotificationKind::Commented));
        self::assertSame(EmailDelivery::Off, $preference->delivery(NotificationKind::Shared));

        // Twice is the same.
        $this->client->request('POST', $this->link('digest'));
        self::assertResponseIsSuccessful();
    }

    public function testASignedInPersonOfTheOrganisationKeepsTheirSession(): void
    {
        $this->browser->signIn();

        $this->client->request('POST', $this->link('assigned'));

        self::assertResponseIsSuccessful();
        self::assertSame(EmailDelivery::Off, $this->preference()?->delivery(NotificationKind::Assigned));
        $this->client->request('GET', '/account/notifications');
        self::assertResponseIsSuccessful();
    }

    public function testThePageIsInTheLanguageOfThePerson(): void
    {
        $this->context->switchTo($this->tenant);
        $this->em->getRepository(Membership::class)->findOneBy([])?->user()->changeLocale('cs');
        $this->em->flush();
        $this->context->reset();

        $crawler = $this->client->request('GET', $this->link('assigned'));

        self::assertSelectorTextContains('h1', 'Vypnout e-maily');
        self::assertSame('Vypnout e-maily o úkolech, které vám někdo přiřadí?', trim($crawler->filter('[data-unsubscribe-question]')->text()));
        self::assertSame('cs', $crawler->filter('html')->attr('lang'));
        $crawler = $this->client->submit($crawler->filter('form')->form());
        self::assertSame('E-maily o přiřazených úkolech už nepřijdou.', trim($crawler->filter('[data-unsubscribe-done]')->text()));
    }

    public function testAChangedLinkIsNotFound(): void
    {
        $link = $this->link('mentioned');

        foreach ([
            str_replace('/mentioned?', '/assigned?', $link),
            str_replace('lang=en', 'lang=cs', $link),
            preg_replace('/_hash=.{4}/', '_hash=AAAA', $link),
            strstr($link, '?', true),
            $link.'&extra=1',
        ] as $changed) {
            $this->client->request('GET', (string) $changed);
            self::assertResponseStatusCodeSame(404);
            $this->client->request('POST', (string) $changed);
            self::assertResponseStatusCodeSame(404);
        }
        self::assertNull($this->preference());
    }

    public function testASignedLinkOfNothingKnownIsNotFound(): void
    {
        $signer = self::getContainer()->get('uri_signer');
        $base = 'http://localhost/unsubscribe/'.$this->tenant->toString().'/';

        $this->client->request('GET', $signer->sign($base.$this->membership()->id().'/everything'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $signer->sign($base.$this->membership()->id().'/everything'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $signer->sign($base.'0190a0a0-0000-7000-8000-000000000000/mentioned'));
        self::assertResponseStatusCodeSame(404);
    }

    private function link(string $what): string
    {
        return self::getContainer()->get(NotificationLinks::class)->unsubscribe($this->membership(), $what);
    }

    private function membership(): Membership
    {
        $this->context->switchTo($this->tenant);
        try {
            $membership = $this->em->getRepository(Membership::class)->findOneBy([]);
            self::assertInstanceOf(Membership::class, $membership);
            $membership->tenantId();

            return $membership;
        } finally {
            $this->context->reset();
        }
    }

    private function preference(): ?NotificationPreference
    {
        $this->context->switchTo($this->tenant);
        try {
            $this->em->clear();

            return $this->em->getRepository(NotificationPreference::class)->findOneBy([]);
        } finally {
            $this->context->reset();
        }
    }
}
