<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Markdown;

use Foresi\Shared\Application\TaskReferenceSyntax;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Reads "[text](task:UUID)" as a reference to a task, before the link parser
 * could take it for a link of a scheme the sanitiser drops.
 */
final class TaskReferenceParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(TaskReferenceSyntax::PATTERN);
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        [$text, $id] = $inlineContext->getSubMatches();
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new TaskReference(TaskReferenceSyntax::text($text), strtolower($id)));

        return true;
    }
}
