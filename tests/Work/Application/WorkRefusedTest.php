<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Shared\Application\RefusalKind;
use Foresi\Work\Application\WorkRefused;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal of Work as the API answers it (ADR 0032): a kind for each
 * reason, the codes every module shares for what is not found or not allowed,
 * and a message for agents.
 */
final class WorkRefusedTest extends TestCase
{
    public function testEachReasonHasAKindAndAMessage(): void
    {
        foreach (WorkRefused::REASONS as $reason) {
            $refused = new WorkRefused($reason);
            self::assertContains($refused->kind()->status(), [400, 403, 404, 409], $reason);
            self::assertNotSame('', $refused->getMessage(), $reason);
            self::assertSame([], $refused->details());
        }
    }

    public function testTheKindsDecideTheStatus(): void
    {
        self::assertSame([RefusalKind::NotFound, 'not_found'], [new WorkRefused('unknown')->kind(), new WorkRefused('unknown')->reason()]);
        self::assertSame([RefusalKind::Forbidden, 'forbidden'], [new WorkRefused('not_allowed')->kind(), new WorkRefused('not_allowed')->reason()]);
        self::assertSame(RefusalKind::Forbidden, new WorkRefused('task_not_yours')->kind());
        self::assertSame(RefusalKind::Conflict, new WorkRefused('archived')->kind());
        self::assertSame(RefusalKind::Conflict, new WorkRefused('timer_not_running')->kind());
        self::assertSame(RefusalKind::Invalid, new WorkRefused('name')->kind());
        // The translation key of the screens keeps the reason itself.
        self::assertSame('unknown', new WorkRefused('unknown')->reason);
    }

    public function testTheFieldIsTheOneOfTheInput(): void
    {
        self::assertSame('body', new WorkRefused('comment_empty')->field());
        self::assertNull(new WorkRefused('name')->field());
        self::assertSame('title', new WorkRefused('name')->about('title')->field());
        self::assertSame('dueOn', new WorkRefused('date', 'dueOn')->field());
        // A field the caller names wins over the one of the reason.
        self::assertSame('text', new WorkRefused('comment_empty', 'text')->field());
    }
}
