<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\TaxOnReceipt;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\MailTemplateKind;

/**
 * The corners of advances: how lines group, which account a tax document names,
 * documents of several rates and treatments, and what each read model leaves out.
 */
final class AdvanceEdgeCasesTest extends AdvanceTestCase
{
    public function testTheDefaultsOfTaxOnReceipt(): void
    {
        self::assertFalse(TaxOnReceipt::arises()->crossBorder);
        self::assertFalse(TaxOnReceipt::none(TaxOnReceipt::CONSUMER)->crossBorder);
        self::assertSame('2026-11-20', TaxOnReceipt::none(TaxOnReceipt::CONSUMER)->deadline(new \DateTimeImmutable('2026-11-05'))->format('Y-m-d'));
    }

    public function testEachDocumentKindHasItsMailTemplate(): void
    {
        self::assertSame([MailTemplateKind::Invoice, MailTemplateKind::Advance, MailTemplateKind::PaymentTaxDocument, MailTemplateKind::CorrectionDocument], array_map(MailTemplateKind::forDocument(...), DocumentKind::cases()));
    }
}
