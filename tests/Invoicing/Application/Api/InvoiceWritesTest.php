<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Api;

use Foresi\Invoicing\Application\Api\InvoiceWrites;
use Foresi\Invoicing\Application\Api\InvoicingRefused;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Tests\Invoicing\Application\Query\InvoicingReadTestCase;
use Foresi\Tests\Party\Support\FirmAccounts;

/**
 * The invoice writes of the API and MCP: what is given is stored as given, what
 * is left out takes the defaults of the form or what the invoice has, and what
 * cannot be read is refused with the field it came from.
 */
final class InvoiceWritesTest extends InvoicingReadTestCase
{
    private const string OTHER_IBAN = 'CZ0708000000001234567890';

    private InvoiceWrites $writes;

    protected function setUp(): void
    {
        parent::setUp();
        FirmAccounts::add($this->em, self::OTHER_IBAN);
        $this->em->flush();
        $this->writes = self::getContainer()->get(InvoiceWrites::class);
    }

    public function testTheProblemsOfTheRulesAreNamedAsTheApiNamesThem(): void
    {
        $refused = InvoicingRefused::of(new IssueRefused([
            new IssueProblem('lines.2.price', 'invoice.problem.price_invalid'),
            new IssueProblem('customer', 'invoice.problem.customer_unknown'),
        ]), static fn (IssueProblem $problem): string => 'invoice.problem.price_invalid' === $problem->key ? 'The price is wrong.' : 'The customer is unknown');

        self::assertSame(['price_invalid', 'lines[2].unit_price', RefusalKind::Invalid], [$refused->reason(), $refused->field(), $refused->kind()]);
        self::assertSame(['problems' => ['lines[2].unit_price: price_invalid', 'customer_id: customer_unknown']], $refused->details());
        self::assertSame('The price is wrong. The customer is unknown.', $refused->getMessage());

        $text = InvoicingRefused::of(new IssueRefused([new IssueProblem('lines.0.text', 'invoice.problem.line_text_missing')]), static fn (IssueProblem $problem): string => '');
        self::assertSame('lines[0].text', $text->field(), 'A field of a line the API names alike keeps its name.');

        $none = InvoicingRefused::of(new IssueRefused([]), static fn (IssueProblem $problem): string => '');
        self::assertSame(['invalid', null, []], [$none->reason(), $none->field(), $none->details()]);

        foreach (['xlines.0.price', 'lines.0.price.extra', "lines.0.price\n"] as $field) {
            $odd = InvoicingRefused::of(new IssueRefused([new IssueProblem($field, 'invoice.problem.price_invalid')]), static fn (IssueProblem $problem): string => '');
            self::assertSame($field, $odd->field(), 'Only lines.N.field is a field of a line.');
        }
    }
}
