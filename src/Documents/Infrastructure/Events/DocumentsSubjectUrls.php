<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Events;

use Foresi\Documents\Infrastructure\Api\DocumentsRoutes;
use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Contract\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class DocumentsSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'documents';
    }

    public function url(EventSubject $subject): ?string
    {
        return 'document' === $subject->type ? $this->urls->generate(DocumentsRoutes::DOCUMENT, ['documentId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
