<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity;

use Foresi\Identity\Application\UserPhotos;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

final class ProfilePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        if (isset($this->user)) {
            self::getContainer()->get(UserPhotos::class)->remove($this->user->id());
        }
        parent::tearDown();
    }

    public function testTheMenuLeadsToTheProfileWithTheNameTheEmailAndTheLanguage(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/');
        $link = $crawler->filter('#user-menu a[data-user-menu-profile]');
        self::assertSame('/account/profile', $link->attr('href'));

        $crawler = $this->client->request('GET', '/account/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Profile');
        $profile = $crawler->filter('[data-profile]')->text();
        self::assertStringContainsString('Example Company', $profile);
        self::assertStringContainsString(IdentityFixtures::EMAIL, $profile);
        self::assertStringContainsString('English', $profile);
        self::assertSame('/account/profile/edit', $crawler->filter('#profile-edit')->attr('href'));
        self::assertSame('/account/profile/photo', $crawler->filter('#profile-photo')->attr('href'));
        self::assertSame('/account/profile/appearance', $crawler->filter('#profile-appearance')->attr('href'));
        self::assertStringContainsString('Standard', $crawler->filter('[data-profile-appearance]')->text());
        self::assertStringContainsString('Normal', $crawler->filter('[data-profile-appearance]')->text());
        self::assertCount(1, $crawler->filter('[data-nav-area="profile"][data-shown]'));
    }

    public function testTheNameAndTheLanguageChangeInTheEditDialog(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/account/profile/edit');
        $this->client->submit($crawler->filter('#profile-form')->form(['displayName' => '  Jana Example  ', 'language' => 'cs']));

        self::assertResponseRedirects('/account/profile');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main h1', 'Profil');
        self::assertStringContainsString('Profil je uložený.', $crawler->filter('main')->text());
        $stored = $this->stored();
        self::assertSame('Jana Example', $stored->displayName());
        self::assertSame('cs', $stored->locale());
    }

    public function testTheAppearanceChangesInItsDialogAndAppliesToEveryPage(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/account/profile');
        self::assertSame(['standard', 'normal'], [$crawler->filter('body')->attr('data-text-size'), $crawler->filter('body')->attr('data-density')]);

        $crawler = $this->client->request('GET', '/account/profile/appearance');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('#appearance-form input[type=radio][name=textSize]'));
        self::assertCount(3, $crawler->filter('#appearance-form input[type=radio][name=density]'));
        self::assertSame('standard', $crawler->filter('#appearance-form input[name=textSize]:checked')->attr('value'));
        self::assertSame('normal', $crawler->filter('#appearance-form input[name=density]:checked')->attr('value'));
        $this->client->submit($crawler->filter('#appearance-form')->form(['textSize' => 'large', 'density' => 'compact']));

        self::assertResponseRedirects('/account/profile');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Your appearance is saved.', $crawler->filter('main')->text());
        self::assertSame(['large', 'compact'], [$this->stored()->textSize(), $this->stored()->density()]);
        self::assertStringContainsString('Larger', $crawler->filter('[data-profile-appearance]')->text());
        self::assertStringContainsString('Compact', $crawler->filter('[data-profile-appearance]')->text());
        // Every page of the person carries it, a list as much as the profile.
        $crawler = $this->client->request('GET', '/contacts');
        self::assertSame(['large', 'compact'], [$crawler->filter('body')->attr('data-text-size'), $crawler->filter('body')->attr('data-density')]);
    }

    public function testAnUnknownAppearanceIsShownAsAnErrorAndNothingChanges(): void
    {
        $this->signIn();

        $token = $this->client->request('GET', '/account/profile/appearance')->filter('#appearance-form input[name=_token]')->attr('value');
        $this->client->request('POST', '/account/profile/appearance', ['_token' => $token, 'textSize' => 'huge', 'density' => 'normal']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#appearance-form', 'Choose one of the text sizes.');
        self::assertSame('standard', $this->stored()->textSize());
    }

    public function testASignedOutPageShowsTheStandardAppearance(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertSame(['standard', 'normal'], [$crawler->filter('body')->attr('data-text-size'), $crawler->filter('body')->attr('data-density')]);
    }

    public function testABlankNameIsShownAsAnErrorAndNothingChanges(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/account/profile/edit');
        $this->client->submit($crawler->filter('#profile-form')->form(['displayName' => '   ']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#profile-form', 'Enter your name.');
        self::assertSame('Example Company', $this->stored()->displayName());
    }

    public function testAnUploadedPhotoShowsInTheMenuAndRemovingItBringsTheInitialsBack(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/account/profile/photo');
        self::assertCount(0, $crawler->filter('#profile-photo-remove'));
        $form = $crawler->filter('#profile-photo-form')->form();
        $this->file($form, 'photo')->upload($this->png(800, 400));
        $this->client->submit($form);

        self::assertResponseRedirects('/account/profile');
        $crawler = $this->client->followRedirect();
        $version = $this->stored()->photoVersion();
        self::assertNotNull($version);
        $src = '/people/'.$this->user->id().'/photo?v='.$version;
        self::assertSame($src, $crawler->filter('#user-menu-button img[data-avatar-photo]')->attr('src'));
        self::assertSame($src, $crawler->filter('[data-profile-avatar] img')->attr('src'));
        // The list of people shows it too.
        $people = $this->client->request('GET', '/settings/people');
        self::assertSame($src, $people->filter('[data-avatar-cell] img[data-avatar-photo]')->attr('src'));

        $this->client->request('GET', $src);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $size = getimagesize($this->photoPath());
        self::assertNotFalse($size);
        self::assertSame([512, 512], [$size[0], $size[1]]);

        $crawler = $this->client->request('GET', '/account/profile/photo');
        $this->client->submit($crawler->filter('#profile-photo-remove')->form());

        self::assertResponseRedirects('/account/profile');
        $crawler = $this->client->followRedirect();
        self::assertNull($this->stored()->photoVersion());
        self::assertCount(0, $crawler->filter('#user-menu-button img'));
        self::assertSame('EC', trim($crawler->filter('#user-menu-button [data-avatar]')->text()));
        $this->client->request('GET', $src);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        $this->signIn();

        $path = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($path, 'not an image');
        $crawler = $this->client->request('GET', '/account/profile/photo');
        $form = $crawler->filter('#profile-photo-form')->form();
        $this->file($form, 'photo')->upload($path);
        $this->client->submit($form);
        unlink($path);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#profile-photo-form', 'Choose a JPEG, PNG or WebP image.');
        self::assertNull($this->stored()->photoVersion());
    }

    public function testThePhotoIsForSignedInPeopleOnly(): void
    {
        $this->user = $this->browser->seed();
        $this->user->changePhoto();
        $this->browser->em()->flush();
        self::getContainer()->get(UserPhotos::class)->store($this->user->id(), (string) file_get_contents($this->png(64, 64)));

        $this->client->request('GET', '/people/'.$this->user->id().'/photo');
        self::assertResponseRedirects();
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->browser->signIn();
        $this->client->request('GET', '/people/00000000-0000-4000-8000-000000000000/photo');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestHasAProfileToo(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/account/profile');

        self::assertResponseIsSuccessful();
    }

    public function testWritesWithoutAValidCsrfTokenAreRefused(): void
    {
        $this->signIn();

        $this->client->request('POST', '/account/profile/edit', ['displayName' => 'Forged']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/account/profile/photo/remove', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Example Company', $this->stored()->displayName());
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->user = $this->browser->seed('en', $role);
        $this->browser->signIn();
    }

    private function stored(): User
    {
        $em = $this->browser->em();
        $em->clear();
        $user = $em->getRepository(User::class)->find($this->user->id());
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function photoPath(): string
    {
        return (string) self::getContainer()->get(UserPhotos::class)->path($this->user->id());
    }

    private function file(Form $form, string $name): FileFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(FileFormField::class, $field);

        return $field;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 60));
        $path = tempnam(sys_get_temp_dir(), 'photo').'.png';
        imagepng($image, $path);

        return $path;
    }
}
