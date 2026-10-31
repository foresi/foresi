<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Notice;

use App\Ui\Notice\ShellNotice;
use App\Ui\Notice\ShellNoticeProvider;
use App\Ui\Notice\ShellNotices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShellNoticesTest extends TestCase
{
    public function testCollectsTheNoticesOfAllProvidersUpToThree(): void
    {
        $notices = new ShellNotices([$this->provider(2), $this->provider(0), $this->provider(5)]);

        self::assertCount(3, $notices->all(true));
        self::assertSame('Notice 1', $notices->all(true)[0]->text);
    }

    public function testAsksNoProviderWithoutATenant(): void
    {
        $provider = new class implements ShellNoticeProvider {
            public function notices(): iterable
            {
                throw new \LogicException('A provider is asked only with a tenant.');
            }
        };

        self::assertSame([], new ShellNotices([$provider])->all(false));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalidNotices(): iterable
    {
        yield 'unknown tone' => ['pink', 'Text', 'Open', '/imports/1'];
        yield 'no text' => ['info', '', 'Open', '/imports/1'];
        yield 'no link label' => ['info', 'Text', '', '/imports/1'];
        yield 'unsafe link' => ['info', 'Text', 'Open', 'javascript:alert(1)'];
    }

    #[DataProvider('invalidNotices')]
    public function testANoticeNeedsAKnownToneTextAndASafeLink(string $tone, string $text, string $link, string $href): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ShellNotice($tone, $text, $link, $href);
    }

    private function provider(int $count): ShellNoticeProvider
    {
        return new readonly class($count) implements ShellNoticeProvider {
            public function __construct(private int $count)
            {
            }

            public function notices(): iterable
            {
                for ($i = 1; $i <= $this->count; ++$i) {
                    yield new ShellNotice('success', 'Notice '.$i, 'Open', '/imports/'.$i);
                }
            }
        };
    }
}
