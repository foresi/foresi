<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Infrastructure\Operation\InvalidInput;
use Foresi\Shared\Infrastructure\Operation\OperationDefinition;
use Foresi\Shared\Infrastructure\Operation\OperationRunner;
use Foresi\Tests\Shared\Infrastructure\Mcp\FakeScopes;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The observers of the runner: told before the handler with the checked input
 * and after it with its answer; a refusal before it stops the operation.
 */
final class OperationObserverTest extends TestCase
{
    public function testTheObserversAreToldBeforeAndAfterTheHandler(): void
    {
        $handler = new ThingHandler();
        $first = new RecordingObserver();
        $second = new RecordingObserver();

        $output = new OperationRunner(new FakeScopes(), [$first, $second])->run(OperationDefinition::of($handler), ['thing_id' => 't', 'name' => 'Lamp']);

        self::assertSame('Changed. Seen. Seen.', $output->summary, 'Each observer gets the answer the one before gave.');
        self::assertSame(['ok' => true], $output->data);
        self::assertInstanceOf(ThingInput::class, $handler->input);
        self::assertSame(['before things.change '.ThingInput::class, 'after things.change Changed.'], $first->seen);
        self::assertSame(['before things.change '.ThingInput::class, 'after things.change Changed. Seen.'], $second->seen);
    }

    public function testARefusalBeforeTheHandlerStopsTheOperation(): void
    {
        $handler = new ThingHandler();
        $refusal = new ThingRefused(RefusalKind::NotFound, 'not_found');
        $later = new RecordingObserver();

        try {
            new OperationRunner(new FakeScopes(), [new RecordingObserver($refusal), $later])->run(OperationDefinition::of($handler), ['thing_id' => 't', 'name' => 'Lamp']);
            self::fail('The operation ran.');
        } catch (ThingRefused $refused) {
            self::assertSame($refusal, $refused);
        }

        self::assertNull($handler->input, 'The handler never ran.');
        self::assertSame([], $later->seen);
    }

    public function testNoObserverIsToldOfAnInputThatIsRefusedOrAFailingHandler(): void
    {
        $observer = new RecordingObserver();
        $runner = new OperationRunner(new FakeScopes(), [$observer]);

        try {
            $runner->run(OperationDefinition::of(new ThingHandler()), ['thing_id' => 't']);
            self::fail('The input was taken.');
        } catch (InvalidInput) {
        }
        self::assertSame([], $observer->seen);

        try {
            $runner->run(OperationDefinition::of(new ThingHandler(new ThingRefused(RefusalKind::Conflict, 'duplicate'))), ['thing_id' => 't', 'name' => 'Lamp']);
            self::fail('The handler did not refuse.');
        } catch (ThingRefused) {
        }
        self::assertSame(['before things.change '.ThingInput::class], $observer->seen, 'Nothing after a handler that refused.');
    }

    public function testAnObserverThatFailsAfterTheHandlerIsLoggedAndTheAnswerKept(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array<mixed>> */
            public array $logged = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = [$level, (string) $message, $context];
            }
        };
        $later = new RecordingObserver();

        $output = new OperationRunner(new FakeScopes(), [new RecordingObserver(afterFailure: new \RuntimeException('Secret words of a mail.')), $later], $logger)->run(OperationDefinition::of(new ThingHandler()), ['thing_id' => 't', 'name' => 'Lamp']);

        self::assertSame('Changed. Seen.', $output->summary, 'The handler committed: its answer is sent, and later observers still see it.');
        self::assertSame(['before things.change '.ThingInput::class, 'after things.change Changed.'], $later->seen);
        self::assertSame([['error', 'An observer failed after the operation had succeeded.', ['operation' => 'things.change', 'observer' => RecordingObserver::class, 'failure' => \RuntimeException::class]]], $logger->logged);
    }
}
