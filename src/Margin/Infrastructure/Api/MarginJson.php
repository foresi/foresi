<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api;

use Foresi\Margin\Application\DocumentAllocationView;
use Foresi\Margin\Application\LineAllocationView;
use Foresi\Margin\Application\MappingView;
use Foresi\Margin\Application\ShareView;
use Foresi\Margin\Application\TermView;
use Foresi\Shared\Infrastructure\Api\JsonValues;

/**
 * The margin as REST and MCP write it: snake_case keys, money in minor units
 * with its currency.
 */
final class MarginJson
{
    /**
     * @return array<string, mixed>
     */
    public static function document(DocumentAllocationView $view): array
    {
        return [
            'document_id' => $view->documentId,
            'label' => $view->label,
            'url' => $view->url,
            'trashed' => $view->trashed,
            'document_allocation' => null === $view->documentRecipient ? null : ['recipient' => $view->documentRecipient, 'recipient_name' => $view->documentRecipientName],
            'lines' => array_map(self::line(...), $view->lines),
            'known_gaps' => $view->knownGaps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(LineAllocationView $line): array
    {
        return [
            'key' => $line->key,
            'text' => $line->text,
            'base_czk' => $line->baseCzk instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($line->baseCzk) : null,
            'allocation' => $line->mapping instanceof MappingView ? self::mapping($line->mapping) : null,
            'shares' => array_map(static fn (ShareView $share): array => [
                'recipient' => $share->recipient,
                'recipient_name' => $share->recipientName,
                'amount' => JsonValues::money($share->amountCzk),
                'source' => $share->source,
            ], $line->shares),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapping(MappingView $mapping): array
    {
        return [
            'kind' => $mapping->kind,
            'shares' => array_map(static fn (TermView $term): array => [
                'recipient' => $term->recipient,
                'recipient_name' => $term->recipientName,
                'percent' => $term->percent,
                'amount' => $term->amountCzk instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($term->amountCzk) : null,
                'rest' => $term->rest,
            ], $mapping->terms),
        ];
    }
}
