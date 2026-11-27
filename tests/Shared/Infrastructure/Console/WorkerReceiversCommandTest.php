<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Console;

use Foresi\Shared\Infrastructure\Console\WorkerReceiversCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The transports a worker consumes come from the installed modules, in the
 * order it takes them: a module's own transport, such as webhooks, exists and
 * is consumed only where the module is installed.
 */
final class WorkerReceiversCommandTest extends TestCase
{
    public function testTheCoreTransportsComeFirstTheModulesNextAndTheRegisterChecksLast(): void
    {
        $output = new BufferedOutput();

        new WorkerReceiversCommand($this->receivers(['registry', 'webhooks', 'async', 'failed', 'events', 'acme_fleet', 'mail', 'scheduler_foresi']))($output);

        self::assertSame("scheduler_foresi mail events async acme_fleet webhooks registry\n", $output->fetch());
    }

    public function testWithoutAModuleItsTransportIsNotConsumed(): void
    {
        self::assertSame(['scheduler_foresi', 'mail', 'events', 'async', 'registry'], new WorkerReceiversCommand($this->receivers(['async', 'registry', 'mail', 'events', 'failed', 'scheduler_foresi']))->receivers());
    }

    /**
     * A receiver locator as Messenger builds it: each transport by its name and by its service id.
     *
     * @param list<string> $names
     *
     * @return ServiceLocator<object>
     */
    private function receivers(array $names): ServiceLocator
    {
        $factories = [];
        foreach ($names as $name) {
            $factories[$name] = static fn (): object => new \stdClass();
            $factories['messenger.transport.'.$name] = static fn (): object => new \stdClass();
        }

        return new ServiceLocator($factories);
    }
}
