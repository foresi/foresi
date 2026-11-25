<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\AiReader\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MeasureCommandTest extends KernelTestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        foreach (['AI_READER_PROVIDER', 'AI_READER_API_KEY'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        if ('' !== $this->directory) {
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testItNeedsTheReaderToBeSetUp(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['directory' => sys_get_temp_dir()]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('not set up', $tester->getDisplay());
    }

    public function testAnEmptyDirectoryWritesNothing(): void
    {
        $_ENV['AI_READER_PROVIDER'] = $_SERVER['AI_READER_PROVIDER'] = 'claude';
        $_ENV['AI_READER_API_KEY'] = $_SERVER['AI_READER_API_KEY'] = 'sk-test-not-a-real-key';
        $this->directory = sys_get_temp_dir().'/ai-reader-empty-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $tester = $this->tester();

        $status = $tester->execute(['directory' => $this->directory, '--model' => 'claude-example-large']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('', $tester->getDisplay());
    }

    public function testAMissingDirectoryIsRefused(): void
    {
        $_ENV['AI_READER_PROVIDER'] = $_SERVER['AI_READER_PROVIDER'] = 'claude';
        $_ENV['AI_READER_API_KEY'] = $_SERVER['AI_READER_API_KEY'] = 'sk-test-not-a-real-key';
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['directory' => '/nonexistent/example']));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('ai-reader:measure'));
    }
}
