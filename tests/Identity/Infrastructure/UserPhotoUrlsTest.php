<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Infrastructure;

use Doctrine\DBAL\Connection;
use Foresi\Identity\Infrastructure\Photo\UserPhotoUrls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class UserPhotoUrlsTest extends TestCase
{
    public function testAPageOfAvatarsAsksTheDatabaseOnceAndAResetAsksAgain(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchAllKeyValue')
            ->with(self::stringContains('photo_version IS NOT NULL'))
            ->willReturn(['user-1' => 'abc']);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::atLeastOnce())->method('generate')->with('app_user_photo', ['id' => 'user-1', 'v' => 'abc'])->willReturn('/people/user-1/photo?v=abc');
        $photos = new UserPhotoUrls($connection, $urls);

        for ($i = 0; $i < 50; ++$i) {
            self::assertSame('/people/user-1/photo?v=abc', $photos->url('user-1'));
            self::assertNull($photos->url('user-2'));
        }

        $photos->reset();
        self::assertSame('/people/user-1/photo?v=abc', $photos->url('user-1'));
    }
}
