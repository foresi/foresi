<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Markdown;

use App\Shared\Application\MentionSyntax;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Reads "@[Display Name](membership:UUID)" as a mention, before the link parser
 * could take its bracket part for a link.
 */
final class MentionParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(MentionSyntax::PATTERN);
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        [$name, $id] = $inlineContext->getSubMatches();
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new Mention($name, strtolower($id)));

        return true;
    }
}
