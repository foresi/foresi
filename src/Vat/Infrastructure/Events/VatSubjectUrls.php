<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Events;

use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Contract\Event\EventSubject;
use Foresi\Vat\Infrastructure\Api\VatApiController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a VAT period can be read in the API. A bank movement marked as no
 * VAT payment has no place there.
 */
final readonly class VatSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'vat';
    }

    public function url(EventSubject $subject): ?string
    {
        return 'vat_period' === $subject->type ? $this->urls->generate(VatApiController::PERIOD, ['key' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
