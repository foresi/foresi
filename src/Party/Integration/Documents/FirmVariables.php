<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Documents;

use Foresi\Documents\Contract\DocumentImageSource;
use Foresi\Documents\Contract\DocumentVariableSource;
use Foresi\Documents\Contract\VariableDefinition;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\FirmImages;
use Foresi\Party\Application\InstanceFirm;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The firm of the instance under {{ firm.* }}, read from the firm settings each
 * time a document is generated. The address book keeps no e-mail or phone of the
 * firm, so a template has none to show. The bank account is the primary one, in
 * groups of four as IBANs are printed. The logo is the one invoices print: its
 * value is the name of the stored image, which never changes, and the document
 * shows it as an image.
 */
final readonly class FirmVariables implements DocumentVariableSource, DocumentImageSource
{
    public function __construct(
        private InstanceFirm $firm,
        private FirmBankAccounts $accounts,
        private FirmImages $images,
        private TranslatorInterface $translator,
    ) {
    }

    public function prefix(): string
    {
        return 'firm';
    }

    public function definitions(): array
    {
        return [
            $this->definition('name', 'Example Firm s.r.o.'),
            $this->definition('address_lines', [['line' => 'Example Street 1'], ['line' => '100 00 Example City'], ['line' => 'CZ']]),
            $this->definition('registration_number', '12345678'),
            $this->definition('vat_id', 'CZ12345678'),
            $this->definition('bank_account', 'CZ65 0800 0000 1920 0014 5399'),
            $this->definition('logo', 'logo.png', image: true),
        ];
    }

    public function values(array $links): array
    {
        $firm = $this->firm->find();
        $iban = $this->accounts->primary()?->iban();

        return [
            'name' => $firm?->name() ?? '',
            'address_lines' => $firm instanceof \Foresi\Party\Domain\LegalEntity ? AddressLines::of($firm->address()) : [],
            'registration_number' => $firm?->registrationNumber()->toString() ?? '',
            'vat_id' => $firm?->vatId()?->toString() ?? '',
            'bank_account' => null === $iban ? '' : trim(chunk_split($iban, 4, ' ')),
            'logo' => $firm?->logo() ?? '',
        ];
    }

    public function imageFile(string $variable, string $value): ?string
    {
        if ('logo' !== $variable) {
            return null;
        }
        try {
            return $this->images->path($value);
        } catch (\InvalidArgumentException|\RuntimeException) {
            return null;
        }
    }

    /**
     * @param string|list<array<string, string>> $example
     */
    private function definition(string $name, string|array $example, bool $image = false): VariableDefinition
    {
        return new VariableDefinition($name, $this->translator->trans('party.document_variable.firm.'.$name), $example, $image);
    }
}
