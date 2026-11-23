<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\PageSettings;
use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;

// The world of the sales spec: the stages the migration seeds, the NDA type it seeds with one
// template that reads the deal and the client, the firm, and a client to sell to.
$ndaType = $entityManager->getRepository(DocumentType::class)->findOneBy(['code' => 'nda']);
if (!$ndaType instanceof DocumentType) {
    throw new LogicException('The migration seeds the document type nda.');
}
$dealTemplate = new DocumentTemplate($ndaType, 'NDA for a deal', new DateTimeImmutable());
$entityManager->persist($dealTemplate);
$entityManager->persist(new TemplateVersion($dealTemplate, 1, "<h1>Mutual non-disclosure agreement</h1>\n<p>Between {{ firm.name }} and {{ client.name }}, about {{ deal.title }}.</p>\n<ul>{% for line in deal.lines %}<li>{{ line.name }}: {{ line.quantity }} {{ line.unit }}, {{ line.unit_price }}, {{ line.billing_period }}</li>{% endfor %}</ul>\n<p>{% for total in deal.one_off_totals %}Once {{ total.amount }}. {% endfor %}{% for total in deal.monthly_totals %}Monthly {{ total.amount }}. {% endfor %}</p>\n", new PageSettings(), new DateTimeImmutable()));
$salesFirm = new LegalEntity('Example Sales s.r.o.', new RegistrationNumber('CZ', '12345687'), null, $address(2), VatStatus::NotRegistered, 'Example trade register');
$entityManager->persist($salesFirm);
$firmAccount($salesFirm);
$entityManager->flush();
$createParty->createFrom($party(PartyKind::Company, 'Example Warehouse s.r.o.', [PartyRole::Customer], 'warehouse@example.com', 5, '12345679', 'CZ12345679'));
