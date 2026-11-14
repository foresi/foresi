<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * A draft after the rules: the input to write when nothing was refused, else
 * every refusal, one per field.
 */
final readonly class ParsedParty
{
    /**
     * @param list<PartyRefused> $refusals
     */
    public function __construct(public ?PartyInput $input, public array $refusals)
    {
    }

    /**
     * @throws PartyRefused the first refusal
     */
    public function orRefuse(): PartyInput
    {
        return $this->input ?? throw $this->refusals[0];
    }
}
