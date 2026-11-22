<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\PageSettings;
use Foresi\Documents\Domain\TemplateVersion;

// The world of the documents spec: the NDA type the migration seeds, and one template of it with no
// variables, so a document generates without a linked record. No contact links: the Party
// integration that offers them comes later.
$ndaType = $entityManager->getRepository(DocumentType::class)->findOneBy(['code' => 'nda']);
if (!$ndaType instanceof DocumentType) {
    throw new LogicException('The migration seeds the document type nda.');
}
$ndaTemplate = new DocumentTemplate($ndaType, 'NDA, English', new DateTimeImmutable());
$entityManager->persist($ndaTemplate);
$entityManager->persist(new TemplateVersion($ndaTemplate, 1, "<h1>Mutual non-disclosure agreement</h1>\n<p>The parties keep each other's confidential information secret.</p>\n", new PageSettings(), new DateTimeImmutable()));
$entityManager->flush();
