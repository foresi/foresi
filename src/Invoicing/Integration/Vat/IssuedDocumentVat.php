<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Integration\Vat;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Invoicing\Application\VatRecap;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Infrastructure\Api\InvoiceApiController;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\VatDocumentSource;
use Foresi\Vat\Contract\VatSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The output VAT of the documents the firm issued: the tax documents the
 * accountant gets, which are the invoices, the tax documents on payment and
 * the corrective tax documents not in the trash, each on its tax point with
 * the VAT of its rate summaries converted at its own rate. An advance invoice
 * is no tax document and adds nothing; the tax document on its payment does.
 * Whether a document is paid does not matter.
 */
final readonly class IssuedDocumentVat implements VatDocumentSource
{
    public const string KEY = 'invoicing.issued_vat';

    public function __construct(
        private InvoiceStore $store,
        private IssuedDocumentFactory $documents,
        private ApiTokenContext $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        $grant = $this->tokens->grant();

        return !$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('invoices', false));
    }

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $zone = $from->getTimezone();
        foreach ($this->store->forAccountant($from, $to, false) as $id => $kind) {
            if (DocumentKind::Advance === $kind) {
                continue;
            }
            $document = $this->documents->of($id);
            $taxPoint = new \DateTimeImmutable(($document->taxPointDate ?? throw new \LogicException('A tax document has a tax point.'))->format('Y-m-d'), $zone);
            yield new DocumentVat(
                self::KEY,
                new VatSubject('invoice', $id, $this->urls->generate(InvoiceApiController::SHOW, ['invoiceId' => $id], UrlGeneratorInterface::ABSOLUTE_PATH)),
                $document->number,
                $taxPoint,
                $taxPoint,
                VatRecap::total(VatRecap::of([$document], Currency::CZK), Currency::CZK)->vat,
                Money::ofMinor(0, Currency::CZK),
            );
        }
    }
}
