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
