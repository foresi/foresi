<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Shared\Contract\Field\CustomField;
use Foresi\Shared\Contract\Field\FieldKind;

/**
 * The fleet's custom fields of a contact (ADR 0045): its id is the contact's, and
 * its row exists while the contact has a value. Foresi writes it through
 * Foresi\Shared\Contract\Field\Fields; the fields show in the contact's form and
 * detail, the contact list, its filters and its history.
 */
#[ORM\Entity]
#[ORM\Table(name: 'acme_fleet_contact_extra')]
class ContactExtra implements ContactExtension
{
    public const int MAX_ACCOUNT_CODE = 30;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** acme_fleet_industry */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[CustomField(FieldKind::Select, 'acme_fleet.field.industry', options: ['logistics' => 'acme_fleet.industry.logistics', 'construction' => 'acme_fleet.industry.construction', 'retail' => 'acme_fleet.industry.retail'], filterable: true)]
    private ?string $industry = null;

    /** acme_fleet_contract_until */
    #[ORM\Column(name: 'contract_until', type: 'date_immutable', nullable: true)]
    #[CustomField(FieldKind::Date, 'acme_fleet.field.contract_until', filterable: true, sortable: true)]
    private ?\DateTimeImmutable $contractUntil = null;

    /** acme_fleet_account_code */
    #[ORM\Column(name: 'account_code', type: 'string', length: self::MAX_ACCOUNT_CODE, nullable: true)]
    #[CustomField(FieldKind::Text, 'acme_fleet.field.account_code', filterable: true, searchable: true)]
    private ?string $accountCode = null;

    /** acme_fleet_services */
    #[ORM\Column(type: 'json', nullable: true)]
    #[CustomField(FieldKind::MultiSelect, 'acme_fleet.field.services', options: ['leasing' => 'acme_fleet.service.leasing', 'servicing' => 'acme_fleet.service.servicing', 'insurance' => 'acme_fleet.service.insurance'], filterable: true)]
    private ?array $services = null;

    /** acme_fleet_fleet_manager: the person of the firm who looks after the contact's fleet */
    #[ORM\Column(name: 'fleet_manager', type: 'guid', nullable: true)]
    #[CustomField(FieldKind::User, 'acme_fleet.field.fleet_manager', filterable: true)]
    private ?string $fleetManager = null;

    public function contactId(): string
    {
        return $this->id;
    }
}
