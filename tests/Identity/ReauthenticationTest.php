<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity;

use Foresi\Identity\Application\UserRepository;
use Foresi\Shared\Application\Sealer;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * "Confirm it's you" before the two-factor enrolment and a new API token: a
 * sign-in opens a window of 15 minutes, after it the person gives their password,
 * or with two-factor sign-in a code that the sign-in has not used already.
 */
final class ReauthenticationTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string PROMPT = '/account/confirm?reason=two_factor&target=/account/two-factor';
    private const string TOKEN_PROMPT = '/account/confirm?reason=api_token&target=/settings/api/new';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private string $secret;

    protected function setUp(): void
    {
        self::mockTime('2026-11-26 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->browser->seed();
        $this->secret = Base32::encodeUpperUnpadded(random_bytes(20));
    }

    public function testASignInOpensTheWindow(): void
    {
        $this->browser->signIn();

        $this->client->request('GET', '/account/two-factor');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-two-factor-qr] svg');
    }

    public function testAfterTheWindowTheEnrolmentAsksForThePassword(): void
    {
        $this->browser->signIn();
        self::mockTime('+15 minutes');

        $this->client->request('GET', '/account/two-factor');

        self::assertResponseRedirects(self::PROMPT);
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Confirm it's you");
        self::assertSelectorTextContains('[data-reauthenticate]', 'set up two-factor sign-in');
        self::assertSelectorTextContains('[data-reauthenticate]', '15 minutes');
        $field = $crawler->filter('#reauth-secret');
        self::assertSame('password', $field->attr('type'));
        self::assertSame('current-password', $field->attr('autocomplete'));

        $this->confirm('not-the-password');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-reauthenticate]', 'The password is not right.');
        self::assertSame('true', $this->client->getCrawler()->filter('#reauth-secret')->attr('aria-invalid'));

        $this->confirm(IdentityFixtures::PASSWORD);
        self::assertResponseRedirects('/account/two-factor');
        $this->client->followRedirect();
        self::assertSelectorExists('[data-two-factor-qr] svg');

        // Fresh again: the window runs from the confirmation.
        self::mockTime('+14 minutes');
        $this->client->request('GET', '/account/two-factor');
        self::assertResponseIsSuccessful();
    }

    public function testWithTwoFactorTheTokenFormAsksForAFreshCode(): void
    {
        $this->enrol();
        $this->browser->signIn();
        // The code of the sign-in: the window opens, and the code is spent.
        $signInCode = $this->code();
        $this->submitSignInCode($signInCode);
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/settings/api/new');
        self::assertResponseIsSuccessful();

        self::mockTime('+20 minutes');
        $this->client->request('GET', '/settings/api/new');
        self::assertResponseRedirects(self::TOKEN_PROMPT);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-reauthenticate]', 'create an API token');
        self::assertSame('one-time-code', $crawler->filter('#reauth-secret')->attr('autocomplete'));

        // Still in the reuse cache of the bundle, which runs before the code is checked.
        $this->confirm($signInCode);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-reauthenticate]', 'This code was just used. Wait for the next code.');

        $this->confirm('000000');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-reauthenticate]', 'The code is not right.');

        $code = $this->code();
        $this->confirm($code);
        self::assertResponseRedirects('/settings/api/new');
        $this->client->followRedirect();
        self::assertSelectorExists('#api-token-form');

        // A confirmed code is spent too.
        self::mockTime('+15 minutes');
        $this->client->request('GET', self::TOKEN_PROMPT);
        $this->confirm($code);
        self::assertSelectorTextContains('[data-reauthenticate]', 'This code was just used.');
    }

    public function testInADialogThePromptTakesThePlaceOfTheFormAndThenShowsIt(): void
    {
        $this->enrol();
        $this->browser->signIn();
        $this->submitSignInCode($this->code());
        self::mockTime('+20 minutes');

        $crawler = $this->client->request('GET', '/settings/api/new', server: ['HTTP_X_FORESI_DIALOG' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('dialog [data-reauthenticate]');
        // Back to the page that asked, so it answers in the same request, behind its own checks.
        self::assertSame('/settings/api/new', $crawler->filter('#reauth-form')->attr('action'));
        self::assertSelectorNotExists('#api-token-form');

        $crawler = $this->client->submit($crawler->filter('#reauth-form')->form(['secret' => '000000']), serverParameters: ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertSelectorExists('dialog [data-reauthenticate]');
        self::assertSelectorNotExists('#api-token-form');

        $this->client->submit($crawler->filter('#reauth-form')->form(['secret' => $this->code()]), serverParameters: ['HTTP_X_FORESI_DIALOG' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('dialog #api-token-form');
        self::assertSelectorNotExists('[data-reauthenticate]');
    }

    public function testAttemptsAreThrottled(): void
    {
        $this->browser->signIn();
        self::mockTime('+16 minutes');
        $this->client->request('GET', self::PROMPT);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->confirm('not-the-password');
            self::assertSelectorTextContains('[data-reauthenticate]', 'The password is not right.');
        }
        $this->confirm(IdentityFixtures::PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-reauthenticate]', 'Too many attempts. Try again in');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignTargets(): iterable
    {
        yield 'another host' => ['//evil.example.com/'];
        yield 'a backslash' => ['/\\evil.example.com/'];
        yield 'a scheme' => ['https://evil.example.com/'];
        yield 'a relative path' => ['evil.example.com'];
        yield 'a line break' => ["/account\r\nLocation: https://evil.example.com/"];
    }

    #[DataProvider('foreignTargets')]
    public function testThePromptNeverSendsAnyoneElsewhere(string $target): void
    {
        $this->browser->signIn();

        $this->client->request('GET', '/account/confirm', ['reason' => 'two_factor', 'target' => $target]);

        self::assertResponseRedirects('/');
    }

    public function testAnUnknownReasonIsAskedForPlainly(): void
    {
        $this->browser->signIn();
        self::mockTime('+16 minutes');

        $this->client->request('GET', '/account/confirm', ['reason' => 'something', 'target' => '/account/profile']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-reauthenticate]', 'change something that protects your account');
    }

    private function confirm(string $secret): void
    {
        $this->client->submit($this->client->getCrawler()->filter('#reauth-form')->form(['secret' => $secret]));
    }

    private function enrol(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->findByEmail(IdentityFixtures::EMAIL) ?? throw new \LogicException('The fixture user is missing.');
        $user->enrolTwoFactor(self::getContainer()->get(Sealer::class)->seal($this->secret), ['recovery-hash']);
        $users->save($user);
    }

    private function submitSignInCode(string $code): void
    {
        $crawler = $this->client->request('GET', '/login/code');
        $this->client->submit($crawler->filter('form')->first()->form(['_auth_code' => $code]));
    }

    /**
     * The code of the mocked moment, which the bundle checks against the same clock.
     */
    private function code(): string
    {
        return TOTP::createFromSecret('' !== $this->secret ? $this->secret : throw new \LogicException('No secret.'), Clock::get())->now();
    }
}
