<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Events;

use App\Invoicing\Infrastructure\Api\InvoiceApiController;
use App\Invoicing\Infrastructure\Api\PaymentApiController;
use App\Shared\Application\Event\EventSubjectUrl;
use App\Shared\Domain\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where an invoice and a payment can be read in the API.
 */
final readonly class InvoicingSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'invoicing';
    }

    public function url(EventSubject $subject): ?string
    {
        $route = match ($subject->type) {
            'invoice' => InvoiceApiController::SHOW,
            'payment' => PaymentApiController::SHOW,
            default => null,
        };

        return null === $route ? null : $this->urls->generate($route, ['id' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
