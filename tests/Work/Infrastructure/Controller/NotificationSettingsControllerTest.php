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
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Work\Application\NotificationMailer;
use App\Work\Domain\EmailDelivery;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\NotificationPreference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The person's own notification settings, at /account/notifications.
 */
final class NotificationSettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em, $this->context);
    }

    public function testTheDefaultsAreMentionsAndAssignmentsAtOnceTheRestInTheDigestAtEight(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/account/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Notification settings');
        self::assertSame([
            'Mentions in comments' => 'immediate',
            'Tasks assigned to you' => 'immediate',
            'Comments on tasks you watch' => 'digest',
            'Status changes of tasks you watch' => 'digest',
            'Tasks shared with you' => 'digest',
        ], $this->choices($crawler));
        // Only a mention and an assignment can come at once.
        self::assertSame(['Right away', 'In the daily digest', 'Never'], $crawler->filter('#email-mentioned option')->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame(['digest', 'off'], $crawler->filter('#email-commented option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('8', $crawler->filter('#digest-hour option[selected]')->attr('value'));
        self::assertCount(24, $crawler->filter('#digest-hour option'));
        self::assertSame('0:00', $crawler->filter('#digest-hour option')->first()->text());
        self::assertNull($crawler->filter('#no-email')->attr('checked'));
        self::assertCount(0, $crawler->filter('[data-notification-settings-unavailable]'));
        self::assertCount(1, $crawler->filter('[data-action-bar] button[type="submit"]'));
        // Reached from the user menu and from the inbox.
        self::assertCount(1, $crawler->filter('#user-menu a[href="/account/notifications"]'));
        $inbox = $this->client->request('GET', '/notifications');
        self::assertSame('/account/notifications', $inbox->filter('[data-notifications-settings]')->attr('href'));
    }

    public function testSavingKeepsTheChoices(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/account/notifications');

        $this->client->submit($crawler->filter('[data-notification-settings]')->form([
            'email_mentioned' => 'digest',
            'email_assigned' => 'off',
            'email_commented' => 'off',
            'email_status_changed' => 'digest',
            'email_shared' => 'off',
            'digestHour' => '17',
            'noEmail' => true,
        ]));

        self::assertResponseRedirects('/account/notifications');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Notification settings saved.');
        self::assertSame([
            'Mentions in comments' => 'digest',
            'Tasks assigned to you' => 'off',
            'Comments on tasks you watch' => 'off',
            'Status changes of tasks you watch' => 'digest',
            'Tasks shared with you' => 'off',
        ], $this->choices($crawler));
        self::assertSame('17', $crawler->filter('#digest-hour option[selected]')->attr('value'));
        self::assertNotNull($crawler->filter('#no-email')->attr('checked'));
        $preference = $this->preference();
        self::assertSame(17, $preference->digestHour());
        self::assertTrue($preference->wantsNoEmail());
        self::assertSame(EmailDelivery::Off, $preference->delivery(NotificationKind::Assigned));

        // Saved again, the one row changes; unticked, e-mails are back.
        $this->client->submit($crawler->filter('[data-notification-settings]')->form(['email_mentioned' => 'immediate', 'noEmail' => false]));
        $preference = $this->preference();
        self::assertSame(EmailDelivery::Immediate, $preference->delivery(NotificationKind::Mentioned));
        self::assertFalse($preference->wantsNoEmail());
        self::assertCount(1, $this->preferences());
    }

    public function testAChoiceTheFormDoesNotOfferIsRefused(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/account/notifications');
        $values = $crawler->filter('[data-notification-settings]')->form()->getValues();

        foreach ([['email_commented' => 'immediate'], ['email_shared' => 'soon'], ['digestHour' => '24'], ['digestHour' => '8.5'], ['digestHour' => '']] as $change) {
            $this->client->request('POST', '/account/notifications', array_merge($values, $change));
            self::assertResponseStatusCodeSame(400);
        }
        $without = $values;
        unset($without['email_assigned']);
        $this->client->request('POST', '/account/notifications', $without);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->preferences());
    }

    public function testSavingNeedsTheToken(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/account/notifications');
        $values = $crawler->filter('[data-notification-settings]')->form()->getValues();

        $this->client->request('POST', '/account/notifications', array_merge($values, ['_token' => 'forged']));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->preferences());
    }

    public function testAGuestChoosesTheirsToo(): void
    {
        $this->seed(Role::Guest);
        $crawler = $this->client->request('GET', '/account/notifications');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('[data-notification-settings]')->form(['email_shared' => 'off', 'digestHour' => '6']));

        self::assertResponseRedirects('/account/notifications');
        self::assertSame(6, $this->preference()->digestHour());
        self::assertSame(EmailDelivery::Off, $this->preference()->delivery(NotificationKind::Shared));
    }

    public function testWithoutASystemMailerThePageSaysEMailsAreOffForEveryone(): void
    {
        $this->seed();
        $mailer = self::createStub(NotificationMailer::class);
        $mailer->method('isConfigured')->willReturn(false);
        self::getContainer()->set(NotificationMailer::class, $mailer);

        $crawler = $this->client->request('GET', '/account/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame('This installation does not send e-mail yet, so notifications are in the app only, for everyone.', trim($crawler->filter('[data-notification-settings-unavailable]')->text()));
    }

    private function seed(Role $role = Role::Member): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: $role);
        $this->browser->signIn();
    }

    /**
     * @return array<string, string>
     */
    private function choices(Crawler $crawler): array
    {
        $choices = [];
        $crawler->filter('[data-notification-settings-events] select')->each(static function (Crawler $select) use ($crawler, &$choices): void {
            $label = trim($crawler->filter('label[for="'.$select->attr('id').'"]')->text());
            $choices[$label] = (string) $select->filter('option[selected]')->attr('value');
        });

        return $choices;
    }

    private function preference(): NotificationPreference
    {
        $preferences = $this->preferences();
        self::assertCount(1, $preferences);

        return $preferences[0];
    }

    /**
     * @return list<NotificationPreference>
     */
    private function preferences(): array
    {
        $this->context->switchTo($this->tenant);
        try {
            $this->em->clear();
            $membership = $this->em->getRepository(Membership::class)->findOneBy([]);
            self::assertInstanceOf(Membership::class, $membership);
            self::assertSame(IdentityFixtures::EMAIL, $membership->user()->email());

            return $this->em->getRepository(NotificationPreference::class)->findAll();
        } finally {
            $this->context->reset();
        }
    }
}
