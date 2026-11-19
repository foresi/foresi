<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Events;

use Foresi\Invoicing\Infrastructure\Api\InvoiceApiController;
use Foresi\Invoicing\Infrastructure\Api\PaymentApiController;
use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Domain\Event\EventSubject;
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
        return match ($subject->type) {
            'invoice' => $this->urls->generate(InvoiceApiController::SHOW, ['invoiceId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH),
            'payment' => $this->urls->generate(PaymentApiController::SHOW, ['id' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH),
            default => null,
        };
    }
}
