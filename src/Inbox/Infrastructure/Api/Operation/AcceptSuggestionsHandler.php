<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api\Operation;

use Foresi\Inbox\Application\Api\AcceptSuggestionsInput;
use Foresi\Inbox\Application\DecideSuggestions;
use Foresi\Inbox\Application\Decision;
use Foresi\Inbox\Application\InboxRefused;
use Foresi\Inbox\Application\RecordOutputs;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Inbox\Infrastructure\Api\SuggestionJson;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * Accepts suggestions with the token the request signed in with: deciding
 * reads the item, so the token needs inbox:read besides inbox_decisions:write,
 * and each step the scope of its own action.
 */
final readonly class AcceptSuggestionsHandler implements OperationHandler
{
    public function __construct(private DecideSuggestions $decide, private ScopeGuard $scopes, private SuggestionJson $json)
    {
    }

    public static function input(): string
    {
        return AcceptSuggestionsInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AcceptSuggestionsInput);
        if (!$this->scopes->allows(RecordOutputs::READ_SCOPE)) {
            throw InboxRefused::insufficientScope(RecordOutputs::READ_SCOPE);
        }
        $decisions = $this->decide->accept($input->suggestionIds, $input->edits, $input->unselected);
        $accepted = \count(array_filter($decisions, static fn (Decision $decision): bool => null === $decision->refused && SuggestionState::Accepted === $decision->suggestion?->state()));

        return new OperationOutput(
            ['suggestions' => array_map($this->json->decision(...), $decisions)],
            \sprintf('Accepted %d of %d suggestions; each one\'s state and steps say what ran and what was refused.', $accepted, \count($decisions)),
        );
    }
}
