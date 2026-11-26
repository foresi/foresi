<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\MailTemplates;
use Foresi\Invoicing\Application\MailVariables;
use Foresi\Invoicing\Application\RenderedMail;
use Foresi\Invoicing\Application\UnknownMailVariables;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Domain\MailTemplate;
use Foresi\Invoicing\Domain\MailTemplateKind;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Stored templates, and the built-in text from the translations of the document's
 * language.
 */
final readonly class DoctrineMailTemplates implements MailTemplates
{
    public function __construct(
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private MailVariables $variables,
    ) {
    }

    public function textFor(MailTemplateKind $kind, DocumentLanguage $language): RenderedMail
    {
        $stored = $this->stored($kind, $language);
        if ($stored instanceof MailTemplate) {
            return new RenderedMail($stored->subject(), $stored->body());
        }

        return $this->builtIn($kind, $language);
    }

    public function builtIn(MailTemplateKind $kind, DocumentLanguage $language): RenderedMail
    {
        return new RenderedMail(
            $this->translator->trans($kind->value.'.mail.default.subject', locale: $language->value),
            $this->translator->trans($kind->value.'.mail.default.body', locale: $language->value),
        );
    }

    public function isCustom(MailTemplateKind $kind, DocumentLanguage $language): bool
    {
        return $this->stored($kind, $language) instanceof MailTemplate;
    }

    public function save(MailTemplateKind $kind, DocumentLanguage $language, string $subject, string $body): void
    {
        $inSubject = $this->variables->unknownIn($subject, $kind);
        if (str_contains($subject, '{'.MailVariables::LINK.'}')) {
            $inSubject[] = MailVariables::LINK;
        }
        $inBody = $this->variables->unknownIn($body, $kind);
        if ([] !== $inSubject || [] !== $inBody) {
            throw new UnknownMailVariables($inSubject, $inBody);
        }

        $stored = $this->stored($kind, $language);
        if ($stored instanceof MailTemplate) {
            $stored->change($subject, $body);

            return;
        }
        $this->em->persist(MailTemplate::define($kind, $language, $subject, $body));
    }

    public function reset(MailTemplateKind $kind, DocumentLanguage $language): void
    {
        $stored = $this->stored($kind, $language);
        if ($stored instanceof MailTemplate) {
            $this->em->remove($stored);
        }
    }

    private function stored(MailTemplateKind $kind, DocumentLanguage $language): ?MailTemplate
    {
        $stored = $this->em->createQuery('SELECT t FROM '.MailTemplate::class.' t WHERE t.kind = :kind AND t.language = :language')
            ->setParameter('kind', $kind->value)
            ->setParameter('language', $language->value)
            ->getOneOrNullResult();

        return $stored instanceof MailTemplate ? $stored : null;
    }
}
