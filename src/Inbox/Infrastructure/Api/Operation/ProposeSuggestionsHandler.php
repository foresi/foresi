<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api\Operation;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Inbox\Application\Api\ProposeSuggestionsInput;
use Foresi\Inbox\Application\InboxRefused;
use Foresi\Inbox\Application\ProposeSuggestions;
use Foresi\Inbox\Application\ProposingClient;
use Foresi\Inbox\Application\RecordOutputs;
use Foresi\Inbox\Infrastructure\Api\InboxRoutes;
use Foresi\Inbox\Infrastructure\Api\SuggestionJson;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Proposes suggestions with the token the request signed in with: the agent
 * reads the item first, so its token needs inbox:read besides
 * inbox_suggestions:write. The client's name the person sees is the token's
 * (an OAuth connection's token is named after its client).
 */
final readonly class ProposeSuggestionsHandler implements OperationHandler
{
    public const string UNNAMED = 'API client';

    public function __construct(
        private ProposeSuggestions $propose,
        private ScopeGuard $scopes,
        private ApiTokenContext $tokens,
        private SuggestionJson $json,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public static function input(): string
    {
        return ProposeSuggestionsInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ProposeSuggestionsInput);
        if (!$this->scopes->allows(RecordOutputs::READ_SCOPE)) {
            throw InboxRefused::insufficientScope(RecordOutputs::READ_SCOPE);
        }
        $grant = $this->tokens->grant();
        if (!$grant instanceof ApiTokenGrant) {
            // The runner refuses a request without a token before this.
            throw InboxRefused::forbidden();
        }
        // A token's name may be blank; the person still sees who proposed it.
        $client = '' === trim($grant->tokenName) ? self::UNNAMED : $grant->tokenName;
        $stored = $this->propose->propose($input->itemId, $input->suggestions, new ProposingClient($grant->tokenId, $client));

        return new OperationOutput(
            ['suggestions' => array_map($this->json->of(...), $stored)],
            1 === \count($stored) ? 'Proposed 1 suggestion; it waits for the owner of the item to accept or reject it.' : \sprintf('Proposed %d suggestions; they wait for the owner of the item to accept or reject them.', \count($stored)),
            $this->urls->generate(InboxRoutes::ITEM, ['itemId' => $input->itemId]),
        );
    }
}
