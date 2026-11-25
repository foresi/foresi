<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\ParseFailed;
use Foresi\Banking\Contract\ParseFailure;
use Foresi\Banking\Contract\StatementFile;
use Foresi\Banking\Contract\StatementFormat;

/**
 * The statement formats the installed modules add, and the one that reads a file.
 */
final readonly class StatementFormats
{
    /** @var list<StatementFormat> */
    private array $formats;

    /**
     * @param iterable<StatementFormat> $formats
     */
    public function __construct(iterable $formats)
    {
        $byId = [];
        foreach ($formats as $format) {
            if (isset($byId[$format->id()])) {
                throw new \LogicException(\sprintf('Two statement formats have the id "%s".', $format->id()));
            }
            $byId[$format->id()] = $format;
        }
        ksort($byId);
        $this->formats = array_values($byId);
    }

    /**
     * @return list<StatementFormat> by id
     */
    public function all(): array
    {
        return $this->formats;
    }

    /**
     * The one format that supports the file, or null when none does.
     *
     * @throws ParseFailed when more than one does
     */
    public function for(StatementFile $file): ?StatementFormat
    {
        $supporting = array_values(array_filter($this->formats, static fn (StatementFormat $format): bool => $format->supports($file)));
        if (\count($supporting) > 1) {
            throw new ParseFailed(ParseFailure::Ambiguous, \sprintf('The formats %s all read this file.', implode(', ', array_map(static fn (StatementFormat $format): string => $format->id(), $supporting))));
        }

        return $supporting[0] ?? null;
    }
}
