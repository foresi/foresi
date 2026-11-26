<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Inbox\Application\InboxRefused;
use Foresi\Inbox\Application\QuoteCheck;
use Foresi\Inbox\Application\StepReferences;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class StepReferencesTest extends TestCase
{
    public function testAReferenceToAnEarlierStepStandsAsThePlaceholderAnywhereInTheInput(): void
    {
        $input = ['task_id' => ['$step' => 1, 'field' => 'id'], 'lines' => [['project_id' => ['$step' => 2, 'field' => 'id']]], 'text' => 'Kept'];

        $checked = StepReferences::validate($input, 3, 'suggestions.0.steps.2.input');

        self::assertSame(['task_id' => StepReferences::PLACEHOLDER, 'lines' => [['project_id' => StepReferences::PLACEHOLDER]], 'text' => 'Kept'], $checked);
        self::assertTrue(Id::isValid(StepReferences::PLACEHOLDER));
        self::assertSame([1, 2], StepReferences::positions($input));
    }

    public function testAReferenceToThisStepALaterOneOrNoneIsRefusedWithItsPath(): void
    {
        foreach ([2, 3, 0, -1, '1'] as $step) {
            $this->assertRefused('invalid_reference', 'suggestions.0.steps.1.input.taskId', static fn (): array => StepReferences::validate(['task_id' => ['$step' => $step, 'field' => 'id']], 2, 'suggestions.0.steps.1.input'));
        }
        $this->assertRefused('invalid_reference', 'suggestions.0.steps.0.input.lines.0.projectId', static fn (): array => StepReferences::validate(['lines' => [['project_id' => ['$step' => 1, 'field' => 'id']]]], 1, 'suggestions.0.steps.0.input'));
    }

    public function testOnlyTheIdOfTheRecordCanBeTaken(): void
    {
        $this->assertRefused('invalid_input', 'input.taskId', static fn (): array => StepReferences::validate(['task_id' => ['$step' => 1, 'field' => 'title']], 2, 'input'));
        $this->assertRefused('invalid_input', 'input.taskId', static fn (): array => StepReferences::validate(['task_id' => ['$step' => 1]], 2, 'input'));
        $this->assertRefused('invalid_input', 'input.taskId', static fn (): array => StepReferences::validate(['task_id' => ['$step' => 1, 'field' => 'id', 'more' => true]], 2, 'input'));
    }

    public function testResolvingTakesTheRecordOfTheDoneStep(): void
    {
        $record = Id::generate();

        self::assertSame(['task_id' => $record, 'text' => 'Kept'], StepReferences::resolve(['task_id' => ['$step' => 1, 'field' => 'id'], 'text' => 'Kept'], [1 => $record]));
        $this->assertRefused('reference_unresolved', 'input.taskId', static fn (): array => StepReferences::resolve(['task_id' => ['$step' => 2, 'field' => 'id']], [1 => $record]));
    }

    public function testAQuoteIsFoundByteForByteWithTheLineEndsMadeAlike(): void
    {
        $text = "Dobrý den,\r\nplease find our offer below.\r\nRegards";

        // In characters: "Dobrý den," is 10 of them, its line end one.
        self::assertSame(11, QuoteCheck::offset($text, 'please find'));
        self::assertSame(10, QuoteCheck::offset($text, "\nplease"));
        self::assertSame(10, QuoteCheck::offset($text, "\r\nplease"));
        self::assertSame(0, QuoteCheck::offset($text, 'Dobrý'));
        self::assertNull(QuoteCheck::offset($text, 'Please find'));
        self::assertNull(QuoteCheck::offset($text, 'please  find'));
        self::assertNull(QuoteCheck::offset(null, 'please'));
        self::assertNull(QuoteCheck::offset($text, ''));
    }

    private function assertRefused(string $reason, string $field, \Closure $work): void
    {
        try {
            $work();
        } catch (InboxRefused $refused) {
            self::assertSame([$reason, $field], [$refused->reason(), $refused->field()]);

            return;
        }
        self::fail(\sprintf('Expected the refusal %s.', $reason));
    }
}
