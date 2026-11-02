<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\MailTemplates;
use App\Invoicing\Application\MailVariables;
use App\Invoicing\Application\RenderedMail;
use App\Invoicing\Application\UnknownMailVariables;
use App\Invoicing\Domain\MailTemplate;
use App\Invoicing\Domain\MailTemplateKind;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Stored templates, and the built-in text from the translations of the document's
 * language. The tenant filter hides the rows of other tenants.
 */
final readonly class DoctrineMailTemplates implements MailTemplates
{
    public function __construct(
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private MailVariables $variables,
    ) {
    }

    public function textFor(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language): RenderedMail
    {
        $stored = $this->stored($firm, $kind, $language);
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

    public function isCustom(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language): bool
    {
        return $this->stored($firm, $kind, $language) instanceof MailTemplate;
    }

    public function save(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language, string $subject, string $body): void
    {
        $inSubject = $this->variables->unknownIn($subject);
        if (str_contains($subject, '{'.MailVariables::LINK.'}')) {
            $inSubject[] = MailVariables::LINK;
        }
        $inBody = $this->variables->unknownIn($body);
        if ([] !== $inSubject || [] !== $inBody) {
            throw new UnknownMailVariables($inSubject, $inBody);
        }

        $stored = $this->stored($firm, $kind, $language);
        if ($stored instanceof MailTemplate) {
            $stored->change($subject, $body);

            return;
        }
        $this->em->persist(MailTemplate::define($firm, $kind, $language, $subject, $body));
    }

    public function reset(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language): void
    {
        $stored = $this->stored($firm, $kind, $language);
        if ($stored instanceof MailTemplate) {
            $this->em->remove($stored);
        }
    }

    private function stored(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language): ?MailTemplate
    {
        $stored = $this->em->createQuery('SELECT t FROM '.MailTemplate::class.' t WHERE t.firm = :firm AND t.kind = :kind AND t.language = :language')
            ->setParameter('firm', $firm->id())
            ->setParameter('kind', $kind->value)
            ->setParameter('language', $language->value)
            ->getOneOrNullResult();

        return $stored instanceof MailTemplate ? $stored : null;
    }
}
