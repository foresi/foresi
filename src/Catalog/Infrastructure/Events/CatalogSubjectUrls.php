<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Events;

use Foresi\Catalog\Infrastructure\Api\CatalogApiController;
use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Contract\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the product an event is about is read over the API.
 */
final readonly class CatalogSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'catalog';
    }

    public function url(EventSubject $subject): ?string
    {
        return 'product' === $subject->type ? $this->urls->generate(CatalogApiController::PRODUCT, ['productId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
