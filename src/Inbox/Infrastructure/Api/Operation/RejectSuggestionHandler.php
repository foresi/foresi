<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api\Operation;

use Foresi\Inbox\Application\Api\RejectSuggestionInput;
use Foresi\Inbox\Application\DecideSuggestions;
use Foresi\Inbox\Application\Decision;
use Foresi\Inbox\Application\InboxRefused;
use Foresi\Inbox\Application\RecordOutputs;
use Foresi\Inbox\Infrastructure\Api\SuggestionJson;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class RejectSuggestionHandler implements OperationHandler
{
    public function __construct(private DecideSuggestions $decide, private ScopeGuard $scopes, private SuggestionJson $json)
    {
    }

    public static function input(): string
    {
        return RejectSuggestionInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RejectSuggestionInput);
        if (!$this->scopes->allows(RecordOutputs::READ_SCOPE)) {
            throw InboxRefused::insufficientScope(RecordOutputs::READ_SCOPE);
        }
        $suggestion = $this->decide->reject($input->suggestionId, $input->reason);

        return new OperationOutput($this->json->decision(new Decision($suggestion->id(), $suggestion)), 'Rejected the suggestion; no further step of it runs, and steps already done keep what they made.');
    }
}
