<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Party\Application\FirmImages;
use App\Party\Application\FirmImageType;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Party\Infrastructure\Http\FilesystemFirmImages;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Logo and stamp on the firm form. The images are synthetic, drawn here.
 */
final class FirmLogoTest extends WebTestCase
{
    private const int MEGABYTE = 1_048_576;

    private KernelBrowser $client;
    private TenantContext $context;
    private TenantId $tenant;
    private string $imageDir;

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
        $directory = self::getContainer()->getParameter('app.firm_image_dir');
        $this->imageDir = $directory;
        [$this->tenant] = $browser->seed(['Tenant A']);
        $browser->signIn();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->temporary);
        $this->removeTenantImages();
        parent::tearDown();
    }

    public function testThePublicFolderHoldsNoImages(): void
    {
        $root = self::getContainer()->getParameter('kernel.project_dir');

        self::assertStringStartsNotWith($root.'/public', $this->imageDir);
        self::assertStringStartsWith('/', $this->imageDir, 'relative to the working directory it could end up below public/');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accepted(): iterable
    {
        yield 'PNG as a logo' => ['logo', 'png'];
        yield 'JPEG as a logo' => ['logo', 'jpg'];
        yield 'PNG as a stamp' => ['stamp', 'png'];
        yield 'JPEG as a stamp' => ['stamp', 'jpg'];
    }

    #[DataProvider('accepted')]
    public function testAnImageIsStoredUnderItsHash(string $field, string $extension): void
    {
        $firm = $this->firm();
        $bytes = 'png' === $extension ? $this->png() : $this->jpeg();

        $this->saveFirm($firm, [$field => $this->upload($bytes, 'image.'.$extension)]);

        self::assertResponseRedirects('/settings/firms');
        $stored = $this->stored($firm);
        $name = 'logo' === $field ? $stored->logo() : $stored->stamp();
        self::assertSame(hash('sha256', $bytes).'.'.$extension, $name);
        self::assertSame($bytes, file_get_contents($this->images()->path($this->tenant, $name)));
    }

    public function testAnImageOfExactlyTheLimitPasses(): void
    {
        $firm = $this->firm();

        $this->saveFirm($firm, ['logo' => $this->upload(str_pad($this->png(), self::MEGABYTE, "\0"), 'large.png')]);

        self::assertResponseRedirects('/settings/firms');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg'];
        yield 'SVG renamed' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.png'];
        yield 'PHP renamed' => ['<?php echo "x";', 'logo.png'];
        yield 'HTML renamed' => ['<html><script>alert(1)</script></html>', 'logo.jpg'];
        yield 'a GIF' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'logo.gif'];
        yield 'empty' => ['', 'logo.png'];
    }

    #[DataProvider('refused')]
    public function testWhatIsNoImageGetsAFormError(string $bytes, string $name): void
    {
        $firm = $this->firm();

        $this->saveFirm($firm, ['logo' => $this->upload($bytes, $name)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#logo-error');
        self::assertNull($this->stored($firm)->logo());
        self::assertSame([], $this->storedFiles());
    }

    public function testAHeaderWithoutImageDataIsRefusedBeforeItIsDecoded(): void
    {
        $firm = $this->firm();

        $this->saveFirm($firm, ['logo' => $this->upload($this->pngHeader(100_000, 100_000), 'logo.png')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#logo-error', '3000');
        self::assertSame([], $this->storedFiles());
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function dimensions(): iterable
    {
        yield 'wide at the limit' => [3000, 1, true];
        yield 'tall at the limit' => [1, 3000, true];
        yield 'too wide' => [3001, 1, false];
        yield 'too tall' => [1, 3001, false];
    }

    #[DataProvider('dimensions')]
    public function testThePixelSizeIsCapped(int $width, int $height, bool $accepted): void
    {
        self::assertSame($accepted ? null : 'dimensions', FirmImageType::problem($this->png($width, $height)));
    }

    public function testAPngThatDoesNotDecodeIsRefused(): void
    {
        $firm = $this->firm();

        $this->saveFirm($firm, ['logo' => $this->upload($this->pngHeader(10, 10), 'logo.png')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#logo-error', 'PNG or JPEG');
    }

    public function testACutOffJpegIsRefused(): void
    {
        $jpeg = $this->jpeg(64, 64);

        self::assertSame('type', FirmImageType::problem(substr($jpeg, 0, (int) (\strlen($jpeg) * 0.8))));
    }

    public function testACutOffPngIsRefused(): void
    {
        $png = $this->png(64, 64);

        self::assertSame('type', FirmImageType::problem(substr($png, 0, \strlen($png) - 20)));
    }

    public function testACmykJpegIsRefused(): void
    {
        $firm = $this->firm();

        $this->saveFirm($firm, ['stamp' => $this->upload($this->cmykJpeg(), 'stamp.jpg')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#stamp-error', 'RGB');
        self::assertSame([], $this->storedFiles());
    }

    public function testTheDecoderLeavesItsSettingAsItFoundIt(): void
    {
        $before = \ini_get('gd.jpeg_ignore_warning');

        FirmImageType::problem($this->jpeg());

        self::assertSame($before, \ini_get('gd.jpeg_ignore_warning'));
    }

    public function testAStampIsCheckedLikeALogo(): void
    {
        $firm = $this->firm();

        $this->saveFirm($firm, ['stamp' => $this->upload('<?php echo "x";', 'stamp.png')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#stamp-error');
    }

    public function testAnOversizeFileGetsAFormError(): void
    {
        $firm = $this->firm();

        $this->saveFirm($firm, ['logo' => $this->upload(str_pad($this->png(), self::MEGABYTE + 1, "\0"), 'large.png')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#logo-error', '1 MB');
    }

    public function testABodyOverThePhpLimitGetsAFormError(): void
    {
        $firm = $this->firm();

        // PHP drops such a body, so no token and no file reach the application.
        $this->client->request('POST', '/settings/firms/'.$firm->id().'/edit', [], [], ['CONTENT_LENGTH' => '9000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#logo-error', '1 MB');
        self::assertSelectorExists('#name[value="Example Firm s.r.o."]');

        $this->client->request('POST', '/settings/firms/new', [], [], ['CONTENT_LENGTH' => '9000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#logo-error', '1 MB');
    }

    public function testAFailedUploadGetsAFormError(): void
    {
        $firm = $this->firm();
        $path = $this->temporaryFile('');

        $this->saveFirm($firm, ['logo' => new UploadedFile($path, 'logo.png', 'image/png', \UPLOAD_ERR_INI_SIZE, true)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#logo-error');
    }

    public function testASecondUploadLeavesTheFirstFileOnDisk(): void
    {
        $firm = $this->firm();
        $first = $this->png();
        $second = $this->jpeg();

        $this->saveFirm($firm, ['logo' => $this->upload($first, 'first.png')]);
        $this->saveFirm($firm, ['logo' => $this->upload($second, 'second.jpg')]);

        self::assertSame(hash('sha256', $second).'.jpg', $this->stored($firm)->logo());
        self::assertTrue(is_file($this->images()->path($this->tenant, hash('sha256', $first).'.png')));
    }

    public function testSavingWithoutAFileKeepsTheImages(): void
    {
        $firm = $this->firm();
        $this->saveFirm($firm, ['logo' => $this->upload($this->png(), 'logo.png'), 'stamp' => $this->upload($this->jpeg(), 'stamp.jpg')]);

        $this->saveFirm($firm, []);

        self::assertNotNull($this->stored($firm)->logo());
        self::assertNotNull($this->stored($firm)->stamp());
    }

    public function testImagesCanBeRemovedAndTheFilesStay(): void
    {
        $firm = $this->firm();
        $this->saveFirm($firm, ['logo' => $this->upload($this->png(), 'logo.png'), 'stamp' => $this->upload($this->jpeg(), 'stamp.jpg')]);

        $this->saveFirm($firm, [], ['removeLogo' => '1', 'removeStamp' => '1']);

        self::assertNull($this->stored($firm)->logo());
        self::assertNull($this->stored($firm)->stamp());
        self::assertCount(2, $this->storedFiles());
    }

    public function testANewFirmCanBringItsImages(): void
    {
        $crawler = $this->client->request('GET', '/settings/firms/new');
        $form = $crawler->filter('main form')->form(['name' => 'Example Company s.r.o.', 'registrationNumber' => '12345687', 'vatStatus' => 'not_registered', 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '100 00']);

        $this->client->request('POST', '/settings/firms/new', $form->getPhpValues(), ['logo' => $this->upload($this->png(), 'logo.png')]);

        self::assertResponseRedirects('/settings/firms');
        $this->em()->clear();
        $firms = $this->em()->getRepository(LegalEntity::class)->findAll();
        self::assertSame(hash('sha256', $this->png()).'.png', $firms[0]->logo());
    }

    public function testTheFormIsMultipartAndKnowsWhatIsStored(): void
    {
        $firm = $this->firm();

        $crawler = $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');
        self::assertSame('multipart/form-data', $crawler->filter('main form')->last()->attr('enctype'));
        self::assertSame('image/png,image/jpeg', $crawler->filter('#logo')->attr('accept'));
        self::assertSelectorNotExists('#removeLogo');

        $this->saveFirm($firm, ['logo' => $this->upload($this->png(), 'logo.png')]);
        $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');
        self::assertSelectorExists('#removeLogo');
        self::assertSelectorNotExists('#removeStamp');
    }

    public function testNoPageNamesAStoredImage(): void
    {
        $firm = $this->firm();
        $bytes = $this->png();
        $this->saveFirm($firm, ['stamp' => $this->upload($bytes, 'stamp.png')]);

        $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');

        self::assertStringNotContainsString(hash('sha256', $bytes), (string) $this->client->getResponse()->getContent());
    }

    public function testImagesOfTwoTenantsLiveApart(): void
    {
        $other = TenantId::generate();
        $bytes = $this->png();
        $images = $this->images();

        $name = $images->store($this->tenant, $bytes);
        $images->store($other, $bytes);

        self::assertNotSame($images->path($this->tenant, $name), $images->path($other, $name));
        $this->removeDirectory($this->imageDir.'/'.$other->toString());
    }

    public function testTheFilesAreForTheApplicationAlone(): void
    {
        $images = $this->images();
        $path = $images->path($this->tenant, $images->store($this->tenant, $this->png()));

        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame(0o700, fileperms(\dirname($path)) & 0o777);
    }

    public function testStoringTheSameImageTwiceGivesTheSameName(): void
    {
        $images = $this->images();

        self::assertSame($images->store($this->tenant, $this->png()), $images->store($this->tenant, $this->png()));
    }

    public function testTheStoreRefusesWhatIsNoImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->images()->store($this->tenant, $this->pngHeader(10, 10));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongNames(): iterable
    {
        yield 'parent directory' => ['../../secret.png'];
        yield 'not a hash' => ['logo.png'];
        yield 'another extension' => [str_repeat('a', 64).'.php'];
        yield 'a line break' => [str_repeat('a', 64).".png\n"];
    }

    #[DataProvider('wrongNames')]
    public function testAPathNeverLeavesTheTenantDirectory(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->images()->path($this->tenant, $name);
    }

    public function testAFileThatIsNotThereIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->images()->path($this->tenant, str_repeat('a', 64).'.png');
    }

    private function images(): FirmImages
    {
        return new FilesystemFirmImages($this->imageDir);
    }

    private function png(int $width = 2, int $height = 2): string
    {
        return $this->drawn(imagepng(...), $width, $height);
    }

    private function jpeg(int $width = 2, int $height = 2): string
    {
        return $this->drawn(imagejpeg(...), $width, $height);
    }

    /**
     * @param callable(\GdImage): bool $write
     */
    private function drawn(callable $write, int $width, int $height): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        $write($image);

        return (string) ob_get_clean();
    }

    /**
     * The header of a PNG with those dimensions and no image data.
     */
    private function pngHeader(int $width, int $height): string
    {
        $header = 'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header));
    }

    /**
     * The start of a JPEG whose frame has four colour components, as CMYK has.
     */
    private function cmykJpeg(): string
    {
        return "\xFF\xD8\xFF\xC0\x00\x14\x08\x00\x01\x00\x01\x04\x01\x11\x00\x02\x11\x00\x03\x11\x00\x04\x11\x00\xFF\xD9";
    }

    private function firm(): LegalEntity
    {
        $this->context->switchTo($this->tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
        $this->em()->persist($firm);
        $this->em()->flush();
        $this->context->reset();

        return $firm;
    }

    /**
     * @param array<string, UploadedFile> $files
     * @param array<string, string>       $values
     */
    private function saveFirm(LegalEntity $firm, array $files, array $values = []): void
    {
        $crawler = $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');
        $form = $crawler->filter('main form')->last()->form();
        $this->client->request('POST', '/settings/firms/'.$firm->id().'/edit', $values + $form->getPhpValues(), $files);
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        return new UploadedFile($this->temporaryFile($bytes), $name, null, null, true);
    }

    private function temporaryFile(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'foresi-image');
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return $path;
    }

    private function stored(LegalEntity $firm): LegalEntity
    {
        $this->em()->clear();

        return $this->em()->getRepository(LegalEntity::class)->find($firm->id()) ?? throw new \LogicException('The firm is missing.');
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        return $this->files($this->imageDir.'/'.$this->tenant->toString());
    }

    /**
     * @return list<string>
     */
    private function files(string $directory): array
    {
        $files = is_dir($directory) ? scandir($directory) : false;

        return false === $files ? [] : array_values(array_diff($files, ['.', '..']));
    }

    private function removeTenantImages(): void
    {
        $this->removeDirectory($this->imageDir.'/'.$this->tenant->toString());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach ($this->files($directory) as $file) {
            unlink($directory.'/'.$file);
        }
        rmdir($directory);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
