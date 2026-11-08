<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Attachment\Domain;

use App\Attachment\Domain\Attachment;
use App\Attachment\Domain\FileName;
use App\Shared\Domain\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    private const string ID = '0190a8d2-1234-7abc-8def-0123456789ab';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'a plain name' => ['Offer 2026.pdf', 'Offer 2026.pdf'];
        yield 'a Unix path' => ['../../etc/passwd', 'passwd'];
        yield 'a Windows path' => ['C:\\Users\\jana\\Desktop\\scan.png', 'scan.png'];
        yield 'control characters' => ["in\x00voice\r\n.pdf", 'invoice.pdf'];
        yield 'a right-to-left override' => ["photo\u{202E}gnp.exe", 'photognp.exe'];
        yield 'surrounding space and dots' => ['  .report.txt. ', 'report.txt'];
        yield 'runs of white space' => ["two \t  words.txt", 'two words.txt'];
        yield 'decomposed accents' => ["R\u{030C}i\u{0301}loha.pdf", "\u{0158}\u{00ED}loha.pdf"];
        yield 'nothing left' => ['/../', FileName::FALLBACK];
        yield 'a Unix path and a Windows path' => ['/home/jana/C:\\docs\\a.txt', 'a.txt'];
        yield 'a line separator' => ["a\u{2028}b\u{2029}c.txt", 'abc.txt'];
        yield 'a non-breaking space around' => ["\u{00A0}name.txt\u{00A0}", 'name.txt'];
        yield 'invalid UTF-8' => ["bad\xC3.txt", 'bad?.txt'];
    }

    #[DataProvider('names')]
    public function testANameIsCleaned(string $given, string $expected): void
    {
        self::assertSame($expected, FileName::clean($given));
    }

    public function testALongNameKeepsItsExtensionWithinTheLimit(): void
    {
        $name = FileName::clean(str_repeat('ž', 300).'.pdf');

        self::assertSame(FileName::MAX_LENGTH, mb_strlen($name));
        self::assertStringEndsWith('ž.pdf', $name);
        self::assertSame(FileName::MAX_LENGTH, mb_strlen(FileName::clean(str_repeat('a', 300))));
    }

    public function testANameOfExactlyTheLimitIsKeptWhole(): void
    {
        $accents = str_repeat('ž', FileName::MAX_LENGTH);
        $spaced = str_repeat('a', FileName::MAX_LENGTH - 5).' .pdf';

        self::assertSame($accents, FileName::clean($accents), 'Characters are counted, not bytes.');
        self::assertSame($spaced, FileName::clean($spaced));
    }

    public function testACutNameEndsWithItsExtensionAndNoDanglingSpaceOrDot(): void
    {
        $cut = FileName::clean(str_repeat('a', 250).' '.str_repeat('b', 10).'.pdf');
        self::assertSame(str_repeat('a', 250).'.pdf', $cut);

        $accents = FileName::clean(str_repeat('a', 300).'.'.str_repeat('ž', 15));
        self::assertSame(str_repeat('a', FileName::MAX_LENGTH - 16).'.'.str_repeat('ž', 15), $accents, 'An extension of 16 characters is kept.');

        $tooLong = FileName::clean(str_repeat('a', 300).'.'.str_repeat('b', 16));
        self::assertSame(str_repeat('a', FileName::MAX_LENGTH), $tooLong, 'An extension of 17 characters is cut with the rest.');

        $two = FileName::clean(str_repeat('ž', 300).'.ž');
        self::assertSame(str_repeat('ž', FileName::MAX_LENGTH - 2).'.ž', $two);
    }

    public function testAnAttachmentKeepsACleanNameAndIsDeletedOnce(): void
    {
        $attachment = $this->attachment(name: 'folder/Scan.PNG', mediaType: 'Image/PNG');

        self::assertSame('Scan.PNG', $attachment->fileName());
        self::assertSame('image/png', $attachment->mediaType());
        self::assertTrue($attachment->belongsTo('task', $attachment->ownerId()));
        self::assertFalse($attachment->belongsTo('task_comment', $attachment->ownerId()));
        self::assertFalse($attachment->isDeleted());

        $attachment->delete(new \DateTimeImmutable('2026-11-08 10:00'));
        $attachment->delete(new \DateTimeImmutable('2026-11-09 10:00'));

        self::assertTrue($attachment->isDeleted());
        self::assertSame('2026-11-08 10:00', $attachment->deletedAt()?->format('Y-m-d H:i'));
    }

    public function testTheLongestKindAndMediaTypeAreAccepted(): void
    {
        $attachment = $this->attachment(ownerKind: str_repeat('a', Attachment::MAX_OWNER_KIND_LENGTH), mediaType: 'a/'.str_repeat('B', Attachment::MAX_MEDIA_TYPE_LENGTH - 2));

        self::assertSame(Attachment::MAX_OWNER_KIND_LENGTH, \strlen($attachment->ownerKind()));
        self::assertSame('a/'.str_repeat('b', Attachment::MAX_MEDIA_TYPE_LENGTH - 2), $attachment->mediaType());
    }

    public function testAnAttachmentKeepsWhatItWasGiven(): void
    {
        $uploader = Id::generate();
        $at = new \DateTimeImmutable('2026-11-08 09:00');
        $attachment = new Attachment(self::ID, 'task', Id::generate(), 'a.pdf', 'application/pdf', 3, hash('sha256', 'abc'), 'tenant/key', $uploader, $at);

        self::assertSame([self::ID, 'tenant/key', $uploader, $at, null], [$attachment->id(), $attachment->storageKey(), $attachment->uploadedBy(), $attachment->createdAt(), $attachment->deletedAt()]);
        self::assertSame(3, $attachment->size());
    }

    public function testWithoutATenantStampedOnPersistThereIsNoTenant(): void
    {
        $this->expectException(\LogicException::class);

        $this->attachment()->tenantId();
    }

    /**
     * @return iterable<string, array{array{id?: string, ownerKind?: string, ownerId?: string, mediaType?: string, size?: int, sha256?: string}}>
     */
    public static function invalid(): iterable
    {
        yield 'an id that is not a UUIDv7' => [['id' => 'abc']];
        yield 'an empty kind' => [['ownerKind' => '']];
        yield 'a kind with capitals' => [['ownerKind' => 'Task']];
        yield 'a kind too long' => [['ownerKind' => str_repeat('a', 41)]];
        yield 'an owner that is not an id' => [['ownerId' => '1']];
        yield 'a media type without subtype' => [['mediaType' => 'image']];
        yield 'a media type one character too long' => [['mediaType' => 'a/'.str_repeat('b', Attachment::MAX_MEDIA_TYPE_LENGTH - 1)]];
        yield 'a hash with something before it' => [['sha256' => 'g'.str_repeat('a', 64)]];
        yield 'a hash with something after it' => [['sha256' => str_repeat('a', 64).'g']];
        yield 'a hash with a trailing newline' => [['sha256' => str_repeat('a', 64)."\n"]];
        yield 'an owner with something before the id' => [['ownerId' => 'x'.self::ID]];
        yield 'an owner with something after the id' => [['ownerId' => self::ID.'x']];
        yield 'an owner with a trailing newline' => [['ownerId' => self::ID."\n"]];
        yield 'an empty file' => [['size' => 0]];
        yield 'a short hash' => [['sha256' => 'abc']];
    }

    /**
     * @param array{id?: string, ownerKind?: string, ownerId?: string, mediaType?: string, size?: int, sha256?: string} $values
     */
    #[DataProvider('invalid')]
    public function testAnAttachmentRefusesWhatCannotDescribeAFile(array $values): void
    {
        $this->expectException(\DomainException::class);

        $this->attachment(...$values);
    }

    private function attachment(?string $id = null, string $ownerKind = 'task', ?string $ownerId = null, string $name = 'a.pdf', string $mediaType = 'application/pdf', int $size = 3, ?string $sha256 = null): Attachment
    {
        return new Attachment($id ?? Id::generate(), $ownerKind, $ownerId ?? Id::generate(), $name, $mediaType, $size, $sha256 ?? hash('sha256', 'abc'), 'key', Id::generate(), new \DateTimeImmutable());
    }
}
