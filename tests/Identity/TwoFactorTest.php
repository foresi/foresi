<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\RecoveryCodes;
use App\Shared\Application\Sealer;
use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\IdentityFixtures;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class TwoFactorTest extends WebTestCase
{
    private const string RECOVERY_CODE = 'ABCD-EFGH-JKLM-NPQR';

    private KernelBrowser $client;
    private string $secret;

    protected function setUp(): void
    {
        $this->requireTwoFactor(true);
        $this->client = self::createClient();
        // The test database lives in memory, so every request must share the kernel.
        $this->client->disableReboot();

        $fixtures = new IdentityFixtures(self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
        $fixtures->rebuildSchema();
        $fixtures->join($fixtures->user(), $fixtures->tenant('Tenant A'));
        // A fresh secret per test: a code once accepted is refused again for a while.
        $this->secret = Base32::encodeUpperUnpadded(random_bytes(20));
    }

    protected function tearDown(): void
    {
        $this->requireTwoFactor(false);
        parent::tearDown();
    }

    public function testAUserWithoutTwoFactorReachesOnlyTheEnrolment(): void
    {
        $this->signIn();
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseRedirects('/account/two-factor');

        $this->client->request('GET', '/organisation/choose');
        self::assertResponseRedirects('/account/two-factor');

        $crawler = $this->client->request('GET', '/account/two-factor');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-two-factor-qr] svg');
        $input = $crawler->filter('#setup-code');
        self::assertSame('one-time-code', $input->attr('autocomplete'));
        self::assertSame('numeric', $input->attr('inputmode'));
        self::assertCount(1, $crawler->filter('label[for="setup-code"]'));
    }

    public function testWithTheSwitchOffNoEnrolmentIsForced(): void
    {
        $this->requireTwoFactor(false);
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $fixtures = new IdentityFixtures(self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
        $fixtures->rebuildSchema();
        $fixtures->join($fixtures->user(), $fixtures->tenant('Tenant A'));

        $this->signIn();
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
    }

    public function testAWrongCodeDoesNotEnrol(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/account/two-factor');

        $this->client->submit($crawler->filter('form')->first()->form(['code' => '000000']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $this->client->getCrawler()->filter('#setup-code')->attr('aria-invalid'));
        self::assertFalse($this->user()->hasTwoFactor());
    }

    public function testAValidCodeEnrolsSealsTheSecretAndShowsTheRecoveryCodesOnce(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/account/two-factor');
        $secret = str_replace(' ', '', $crawler->filter('[data-two-factor-key]')->text());
        // The same pending secret on a reload, so a scanned code keeps working.
        self::assertSame($secret, str_replace(' ', '', $this->client->request('GET', '/account/two-factor')->filter('[data-two-factor-key]')->text()));

        $this->client->submit($this->client->getCrawler()->filter('form')->first()->form(['code' => self::currentCode($secret)]));

        self::assertResponseRedirects('/account/two-factor/recovery-codes');
        $codes = $this->client->followRedirect()->filter('[data-recovery-codes] li')->each(static fn (Crawler $item): string => $item->text());
        self::assertCount(RecoveryCodes::COUNT, $codes);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->request('GET', '/account/two-factor/recovery-codes');
        self::assertResponseRedirects('/');

        $user = $this->user();
        self::assertTrue($user->hasTwoFactor());
        $sealed = (string) $user->totpSecretSealed();
        self::assertStringStartsWith('test1:', $sealed);
        self::assertStringNotContainsString($secret, $sealed);
        self::assertSame($secret, self::getContainer()->get(Sealer::class)->open($sealed));
        self::assertTrue($user->hasRecoveryCode(new RecoveryCodes()->hash($codes[0])));
        self::assertFalse($user->hasRecoveryCode($codes[0]));

        // Enrolled: the pages open, the enrolment does not.
        $this->client->request('GET', '/account/two-factor');
        self::assertResponseRedirects('/');
    }

    public function testAnEnrolledUserSignsInWithACode(): void
    {
        $this->enrol();
        $this->signIn();
        $this->assertCodeAsked();

        $this->submitCode('000000');
        self::assertResponseRedirects('/login/code');
        $crawler = $this->client->followRedirect();
        self::assertSame('true', $crawler->filter('#auth-code')->attr('aria-invalid'));
        self::assertSame('one-time-code', $crawler->filter('#auth-code')->attr('autocomplete'));

        $this->submitCode(self::currentCode($this->secret));
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testARecoveryCodeSignsInOnce(): void
    {
        $this->enrol();

        $this->signIn();
        $this->submitCode('abcd efgh jklm npqr');
        self::assertResponseRedirects('/');
        self::assertFalse($this->user()->hasRecoveryCode(new RecoveryCodes()->hash(self::RECOVERY_CODE)));

        $this->signOut();
        $this->signIn();
        $this->submitCode(self::RECOVERY_CODE);
        self::assertResponseRedirects('/login/code');
    }

    public function testCodeAttemptsAreThrottledAcrossNewSignIns(): void
    {
        $this->enrol();
        $this->signIn();
        for ($i = 0; $i < 3; ++$i) {
            $this->submitCode('000000');
        }
        // A correct password again buys no more guesses.
        $this->signOut();
        $this->signIn();
        $this->submitCode('000000');
        $this->submitCode('000000');
        $this->client->followRedirect();
        $wrongCode = $this->client->getCrawler()->filter('[id$="-error"]')->text();

        $this->submitCode(self::currentCode($this->secret));

        self::assertResponseRedirects('/login/code');
        $this->client->followRedirect();
        self::assertNotSame($wrongCode, $this->client->getCrawler()->filter('[id$="-error"]')->text());
        $this->assertCodeAsked();
    }

    public function testATrustedBrowserSkipsTheCodeUntilAReset(): void
    {
        $this->enrol();
        $this->signIn();
        $crawler = $this->client->request('GET', '/login/code');
        $this->client->submit($crawler->filter('form')->first()->form(['_auth_code' => self::currentCode($this->secret), '_trusted' => '1']));
        self::assertResponseRedirects('/');
        self::assertNotNull($this->client->getCookieJar()->get('trusted_device'));

        $this->signOut();
        $this->signIn();
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $user = $this->user();
        $user->resetTwoFactor();
        $user->enrolTwoFactor(self::getContainer()->get(Sealer::class)->seal($this->secret), [new RecoveryCodes()->hash(self::RECOVERY_CODE)]);
        $this->users()->save($user);
        $this->signOut();
        $this->signIn();
        $this->assertCodeAsked();
    }

    private function enrol(): void
    {
        $user = $this->user();
        $user->enrolTwoFactor(self::getContainer()->get(Sealer::class)->seal($this->secret), [new RecoveryCodes()->hash(self::RECOVERY_CODE)]);
        $this->users()->save($user);
    }

    private function user(): User
    {
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        return $this->users()->findByEmail(IdentityFixtures::EMAIL) ?? throw new \LogicException('The fixture user is missing.');
    }

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => IdentityFixtures::EMAIL, '_password' => IdentityFixtures::PASSWORD]));
    }

    private function signOut(): void
    {
        $this->client->getCookieJar()->expire('MOCKSESSID');
        $this->client->getCookieJar()->expire('PHPSESSID');
    }

    private function assertCodeAsked(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseRedirects('http://localhost/login/code');
    }

    private function submitCode(string $code): void
    {
        $crawler = $this->client->request('GET', '/login/code');
        $this->client->submit($crawler->filter('form')->first()->form(['_auth_code' => $code]));
    }

    private static function currentCode(string $secret): string
    {
        return TOTP::createFromSecret('' !== $secret ? $secret : throw new \LogicException('No secret.'))->now();
    }

    private function requireTwoFactor(bool $required): void
    {
        $_SERVER['APP_REQUIRE_2FA'] = $_ENV['APP_REQUIRE_2FA'] = $required ? 'true' : 'false';
    }
}
