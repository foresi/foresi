<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Events;

use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Domain\Event\EventSubject;
use Foresi\TaxesAndContributions\Infrastructure\Api\TaxesApiController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the owner profile can be read in the API.
 */
final readonly class TaxesSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'taxes-and-contributions';
    }

    public function url(EventSubject $subject): ?string
    {
        return 'tax_profile' === $subject->type ? $this->urls->generate(TaxesApiController::PROFILE, [], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
