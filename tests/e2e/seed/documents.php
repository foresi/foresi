<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\DocumentTemplates\Domain\DocumentTemplate;
use Foresi\DocumentTemplates\Domain\DocumentType;
use Foresi\DocumentTemplates\Domain\PageSettings;
use Foresi\DocumentTemplates\Domain\TemplateVersion;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;

// The world of the documents spec: the NDA type the migration seeds, one template of it with no
// variables, so a document generates without a linked record, and one with the firm and the
// client, for a document made from the client's Documents tab.
$ndaType = $entityManager->getRepository(DocumentType::class)->findOneBy(['code' => 'nda']);
if (!$ndaType instanceof DocumentType) {
    throw new LogicException('The migration seeds the document type nda.');
}
$ndaTemplate = new DocumentTemplate($ndaType, 'NDA, English', new DateTimeImmutable());
$entityManager->persist($ndaTemplate);
$entityManager->persist(new TemplateVersion($ndaTemplate, 1, "<h1>Mutual non-disclosure agreement</h1>\n<p>The parties keep each other's confidential information secret.</p>\n", new PageSettings(), new DateTimeImmutable()));
$partiesTemplate = new DocumentTemplate($ndaType, 'NDA with the parties', new DateTimeImmutable());
$entityManager->persist($partiesTemplate);
$entityManager->persist(new TemplateVersion($partiesTemplate, 1, "<h1>Mutual non-disclosure agreement</h1>\n<p>Between {{ firm.name }}, registration number {{ firm.registration_number }}, and {{ client.name }}, registration number {{ client.registration_number }}.</p>\n<p>{% for item in client.address_lines %}{{ item.line }}<br>{% endfor %}</p>\n", new PageSettings(), new DateTimeImmutable()));
$documentsFirm = new LegalEntity('Example Documents s.r.o.', new RegistrationNumber('CZ', '12345687'), null, $address(2), VatStatus::NotRegistered, 'Example trade register');
$entityManager->persist($documentsFirm);
$firmAccount($documentsFirm);
$entityManager->flush();
$createParty->createFrom($party(PartyKind::Company, 'Example Client s.r.o.', [PartyRole::Customer], 'client@example.com', 3, '12345679', 'CZ12345679'));
