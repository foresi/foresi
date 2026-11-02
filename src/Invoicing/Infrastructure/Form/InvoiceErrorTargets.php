<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

/**
 * Where an item of the error list leads on this form. A field that is on the page is
 * its target; what only the firm's settings can fix opens them in a new tab, which
 * keeps the form; a problem nobody can act on from here has no link.
 */
final readonly class InvoiceErrorTargets
{
    public const array LINE_CELLS = ['rate' => 'vat', 'treatment' => 'vat'];
    private const array WITHOUT_LINK = ['form', 'document', 'version'];

    public function __construct(
        private bool $payer,
        private bool $delivered,
        private bool $hasBankAccount,
        private bool $customerPicked,
        private string $firmUrl,
    ) {
    }

    /**
     * The id on the form of a field of a problem: "lines.0.rate" gives "lines-0-vat".
     */
    public static function inputId(string $field): string
    {
        $parts = explode('.', $field);
        if ('lines' === $parts[0] && 3 === \count($parts)) {
            return \sprintf('lines-%s-%s', $parts[1], self::LINE_CELLS[$parts[2]] ?? $parts[2]);
        }

        return implode('-', $parts);
    }

    /**
     * @param string $id  the id the error carries, as in "lines-0-price"
     * @param string $key the translation key of the problem
     *
     * @return array{href: string, external: bool}|null
     */
    public function of(string $id, string $key): ?array
    {
        if ('firm' === $id) {
            return 'invoice.problem.firm_locked' === $key ? null : $this->settings();
        }
        if ('bankAccount' === $id && !$this->hasBankAccount) {
            return $this->settings();
        }
        if (\in_array($id, self::WITHOUT_LINK, true) || ($this->delivered && \in_array($id, ['number', 'series'], true))) {
            return null;
        }

        return ['href' => '#'.$this->field($id), 'external' => false];
    }

    private function field(string $id): string
    {
        if ('lines' === $id) {
            return 'lines-0-text';
        }
        if (!$this->customerPicked && str_starts_with($id, 'customer-')) {
            return 'customer';
        }
        // A firm without VAT has no such cells; the row is what is left to go to.
        if (!$this->payer && 1 === preg_match('/^(lines-\d+)-(vat|priceType|supplyCode)$/D', $id, $parts)) {
            return $parts[1].'-text';
        }

        return $id;
    }

    /**
     * @return array{href: string, external: bool}
     */
    private function settings(): array
    {
        return ['href' => $this->firmUrl, 'external' => true];
    }
}
