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
    /** Starts a signature, which a parser id never does. */
    public const string SIGNATURE_PREFIX = 'parsers:';

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

    /**
     * The version of every parser, by its id.
     *
     * @return array<string, int>
     */
    public function versions(): array
    {
        $versions = [];
        foreach ($this->parsers as $parser) {
            $versions[$parser->id()] = $parser->version();
        }
        ksort($versions, \SORT_STRING);

        return $versions;
    }

    /**
     * A name for the set of parsers as it is now, which changes when a parser is
     * added or removed or its version goes up. A mail no parser could tell apart
     * keeps it, so it is read again only once the set changed.
     */
    public function signature(): string
    {
        $parts = [];
        foreach ($this->versions() as $id => $version) {
            $parts[] = $id.'@'.$version;
        }

        return self::SIGNATURE_PREFIX.substr(hash('sha256', implode("\n", $parts)), 0, 32);
    }
}
