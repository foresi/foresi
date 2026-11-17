<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Events;

use App\Purchasing\Infrastructure\Api\ReceivedDocumentApiController;
use App\Shared\Application\Event\EventSubjectUrl;
use App\Shared\Domain\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a received document can be read in the API.
 */
final readonly class PurchasingSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'purchasing';
    }

    public function url(EventSubject $subject): ?string
    {
        return 'received_document' === $subject->type ? $this->urls->generate(ReceivedDocumentApiController::SHOW, ['documentId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
