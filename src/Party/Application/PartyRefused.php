<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Shared\Application\RefusalKind;
use App\Shared\Application\Refused;

/**
 * What the address book refuses to write, as a reason from a closed list and
 * the field it is about (ADR 0032). The screens turn the reason into their
 * message, the API and MCP into a problem or a refusal; a duplicate carries the
 * ids of the contacts it matched.
 */
final class PartyRefused extends \DomainException implements Refused
{
    /** A field that must have a value has none. */
    public const string REQUIRED = 'required';
    /** A value is longer than its field takes. */
    public const string TOO_LONG = 'too_long';
    /** The kind is not person, sole_trader or company. */
    public const string KIND = 'kind_invalid';
    /** A role is not one of customer, supplier, partner or authority. */
    public const string ROLE = 'role_invalid';
    /** A country the address book does not offer. */
    public const string COUNTRY = 'country_unsupported';
    public const string EMAIL = 'email_invalid';
    public const string REGISTRATION_NUMBER = 'registration_number_invalid';
    public const string VAT_ID = 'vat_id_invalid';
    /** A company needs a registration number or a VAT id. */
    public const string COMPANY_IDENTIFIER = 'company_identifier_required';
    /** Another contact has the registration number or the VAT id. */
    public const string DUPLICATE = 'duplicate';
    /** A contact person must be a person, linked to a company or a sole trader. */
    public const string PERSON = 'person_invalid';
    /** The person is linked to the company already. */
    public const string LINKED = 'already_linked';
    /** The person is not linked to the company. */
    public const string NOT_LINKED = 'not_linked';

    public const array REASONS = [self::REQUIRED, self::TOO_LONG, self::KIND, self::ROLE, self::COUNTRY, self::EMAIL, self::REGISTRATION_NUMBER, self::VAT_ID, self::COMPANY_IDENTIFIER, self::DUPLICATE, self::PERSON, self::LINKED, self::NOT_LINKED];

    private const array MESSAGES = [
        self::REQUIRED => 'The field %s needs a value.',
        self::TOO_LONG => 'The field %s is too long.',
        self::KIND => 'The kind must be person, sole_trader or company.',
        self::ROLE => 'A role must be customer, supplier, partner or authority.',
        self::COUNTRY => 'The field %s names a country the address book does not offer; use an ISO 3166-1 alpha-2 code such as DE.',
        self::EMAIL => 'The e-mail address is not valid.',
        self::REGISTRATION_NUMBER => 'The registration number is not valid for its country.',
        self::VAT_ID => 'The VAT id is not valid: two letters of the country, then the number.',
        self::COMPANY_IDENTIFIER => 'A company needs a registration number or a VAT id.',
        self::DUPLICATE => 'The organisation has a contact with this registration number or VAT id already; find it and update it instead.',
        self::PERSON => 'A contact person must be a person, and is linked to a company or a sole trader.',
        self::LINKED => 'The person is linked to the company already.',
        self::NOT_LINKED => 'The person is not linked to the company.',
    ];

    /**
     * @param list<string> $existingIds the contacts a duplicate matched
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?string $field = null,
        public readonly array $existingIds = [],
    ) {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \LogicException(\sprintf('"%s" is not a reason the address book refuses with.', $reason));
        }
        parent::__construct(\sprintf(self::MESSAGES[$reason], $field ?? ''));
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return match ($this->reason) {
            self::DUPLICATE, self::LINKED => RefusalKind::Conflict,
            self::NOT_LINKED => RefusalKind::NotFound,
            default => RefusalKind::Invalid,
        };
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function details(): array
    {
        return self::DUPLICATE === $this->reason ? ['existing_ids' => $this->existingIds] : [];
    }
}
