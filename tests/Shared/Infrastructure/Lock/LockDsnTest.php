<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Lock;

use Foresi\Shared\Infrastructure\Lock\LockDsnEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

final class LockDsnTest extends KernelTestCase
{
    public function testTheDefaultStoreLocksInsideTheInstance(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $shareDir = $container->getParameter('kernel.share_dir');
        $projectDir = $container->getParameter('kernel.project_dir');
        $factory = $container->get(LockFactory::class);
        $name = 'lock-dsn-test-'.bin2hex(random_bytes(4));

        $lock = $factory->createLock($name);
        self::assertTrue($lock->acquire());
        try {
            $files = glob($shareDir.'/lock/sf.'.$name.'.*.lock');
            self::assertNotFalse($files);
            self::assertCount(1, $files, 'The lock file is in the share directory of the instance.');
            self::assertStringStartsWith($projectDir.'/', $files[0]);
            self::assertSame([], glob(sys_get_temp_dir().'/sf.'.$name.'.*.lock'));
        } finally {
            $lock->release();
            $left = glob($shareDir.'/lock/sf.'.$name.'.*.lock');
            foreach (false === $left ? [] : $left as $file) {
                unlink($file);
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function values(): iterable
    {
        yield 'empty' => ['', 'flock:///srv/a/shared/var/share/prod/lock'];
        yield 'bare flock' => ['flock', 'flock:///srv/a/shared/var/share/prod/lock'];
        yield 'blank around' => [' flock ', 'flock:///srv/a/shared/var/share/prod/lock'];
        yield 'own directory' => ['flock:///var/lock/a', 'flock:///var/lock/a'];
        yield 'shared store' => ['postgresql+advisory://u:p@db.example.com/foresi', 'postgresql+advisory://u:p@db.example.com/foresi'];
    }

    #[DataProvider('values')]
    public function testTheVariableResolves(string $value, string $dsn): void
    {
        $processor = new LockDsnEnvVarProcessor('/srv/a/shared/var/share/prod');

        self::assertSame($dsn, $processor->getEnv('lock_dsn', 'LOCK_DSN', static fn (): string => $value));
    }
}
