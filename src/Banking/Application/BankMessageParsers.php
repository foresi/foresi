<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Finds the parser of a mail among the tagged country modules. A mail two
 * parsers claim is not guessed at: it fails as ambiguous.
 */
final readonly class BankMessageParsers
{
    /**
     * @param iterable<BankMessageParser> $parsers
     */
    public function __construct(private iterable $parsers)
    {
    }

    /**
     * @throws ParseFailed when more than one parser supports the mail
     */
    public function for(InboundMail $mail): ?BankMessageParser
    {
        $supporting = [];
        foreach ($this->parsers as $parser) {
            if ($parser->supports($mail)) {
                $supporting[] = $parser;
            }
        }

        if (\count($supporting) > 1) {
            $ids = array_map(static fn (BankMessageParser $parser): string => $parser->id(), $supporting);

            throw new ParseFailed(ParseFailure::Ambiguous, \sprintf('Parsers %s all support this mail.', implode(', ', $ids)));
        }

        return $supporting[0] ?? null;
    }

    public function byId(string $id): ?BankMessageParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->id() === $id) {
                return $parser;
            }
        }

        return null;
    }
}
