<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Documents\Application\DocumentFilter;
use Foresi\Documents\Application\DocumentsStore;
use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\DocumentLink;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\Signer;
use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineDocumentsStore implements DocumentsStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function type(string $id): ?DocumentType
    {
        return Id::isValid($id) ? $this->em->find(DocumentType::class, $id) : null;
    }

    public function typeByCode(string $code): ?DocumentType
    {
        return $this->em->getRepository(DocumentType::class)->findOneBy(['code' => $code]);
    }

    public function types(bool $withArchived): array
    {
        $query = $this->em->createQueryBuilder()->select('t')->from(DocumentType::class, 't')->orderBy('t.name')->addOrderBy('t.id');
        if (!$withArchived) {
            $query->andWhere('t.archivedAt IS NULL');
        }

        /* @var list<DocumentType> */
        return $query->getQuery()->getResult();
    }

    public function template(string $id): ?DocumentTemplate
    {
        return Id::isValid($id) ? $this->em->find(DocumentTemplate::class, $id) : null;
    }

    public function templates(?string $typeId, bool $withArchived): array
    {
        $query = $this->em->createQueryBuilder()->select('t')->from(DocumentTemplate::class, 't')->orderBy('t.name')->addOrderBy('t.id');
        if (null !== $typeId) {
            $query->andWhere('t.typeId = :type')->setParameter('type', $typeId);
        }
        if (!$withArchived) {
            $query->andWhere('t.archivedAt IS NULL');
        }

        /* @var list<DocumentTemplate> */
        return $query->getQuery()->getResult();
    }

    public function version(string $id): ?TemplateVersion
    {
        return Id::isValid($id) ? $this->em->find(TemplateVersion::class, $id) : null;
    }

    public function latestVersion(string $templateId): ?TemplateVersion
    {
        return $this->versions($templateId)[0] ?? null;
    }

    public function versions(string $templateId): array
    {
        /* @var list<TemplateVersion> */
        return $this->em->createQueryBuilder()->select('v')->from(TemplateVersion::class, 'v')
            ->where('v.templateId = :template')->setParameter('template', $templateId)
            ->orderBy('v.number', 'DESC')
            ->getQuery()->getResult();
    }

    public function document(string $id): ?Document
    {
        return Id::isValid($id) ? $this->em->find(Document::class, $id) : null;
    }

    public function documents(DocumentFilter $filter): array
    {
        $query = $this->em->createQueryBuilder()->select('d')->from(Document::class, 'd')
            ->orderBy('d.createdAt', 'DESC')->addOrderBy('d.id', 'DESC')
            ->setMaxResults(min(max(1, $filter->limit), DocumentFilter::MAX_LIMIT))->setFirstResult(max(0, $filter->offset));
        if (null !== $filter->typeId) {
            $query->andWhere('d.typeId = :type')->setParameter('type', $filter->typeId);
        }
        if ($filter->status instanceof \Foresi\Documents\Domain\DocumentStatus) {
            $query->andWhere('d.status = :status')->setParameter('status', $filter->status->value);
        }
        if (null !== $filter->linkKind && null !== $filter->linkId) {
            $query->andWhere('d.id IN (SELECT IDENTITY(l.document) FROM '.DocumentLink::class.' l WHERE l.kind = :kind AND l.targetId = :target)')
                ->setParameter('kind', $filter->linkKind)->setParameter('target', $filter->linkId);
        }

        /* @var list<Document> */
        return $query->getQuery()->getResult();
    }

    public function links(Document $document): array
    {
        /* @var list<DocumentLink> */
        return $this->em->createQueryBuilder()->select('l')->from(DocumentLink::class, 'l')
            ->where('l.document = :document')->setParameter('document', $document)
            ->orderBy('l.kind')->addOrderBy('l.targetId')
            ->getQuery()->getResult();
    }

    public function signers(Document $document): array
    {
        /* @var list<Signer> */
        return $this->em->createQueryBuilder()->select('s')->from(Signer::class, 's')
            ->where('s.document = :document')->setParameter('document', $document)
            ->orderBy('s.order')->addOrderBy('s.id')
            ->getQuery()->getResult();
    }

    public function signer(Document $document, string $signerId): ?Signer
    {
        $signer = Id::isValid($signerId) ? $this->em->find(Signer::class, $signerId) : null;

        return $signer instanceof Signer && $signer->documentId() === $document->id() ? $signer : null;
    }

    public function add(DocumentType|DocumentTemplate|TemplateVersion|Document|DocumentLink|Signer $record): void
    {
        $this->em->persist($record);
    }

    public function remove(DocumentLink|Signer $record): void
    {
        $this->em->remove($record);
    }
}
