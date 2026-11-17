<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Domain;

use App\Audit\Domain\Audited;
use App\Shared\Domain\Id;
use Doctrine\ORM\Mapping as ORM;

/**
 * When a document is really expected to be paid, which replaces its due date in
 * the forecast, such as a customer who always pays two weeks late. Cash flow
 * keeps it, not the document, and names the document as a forecast subject.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_flow_expected_date')]
#[ORM\UniqueConstraint(name: 'uniq_cash_flow_expected_date_subject', columns: ['subject_type', 'subject_id'])]
class ExpectedDate implements Audited
{
    public const int MAX_SUBJECT_TYPE = 40;
    public const int MAX_SUBJECT_ID = 64;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\Column(name: 'subject_type', type: 'string', length: self::MAX_SUBJECT_TYPE)]
        private readonly string $subjectType,
        #[ORM\Column(name: 'subject_id', type: 'string', length: self::MAX_SUBJECT_ID)]
        private readonly string $subjectId,
        #[ORM\Column(name: 'expected_on', type: 'date_immutable')]
        private \DateTimeImmutable $expectedOn,
    ) {
        $this->id = Id::generate();
    }

    public function moveTo(\DateTimeImmutable $expectedOn): void
    {
        $this->expectedOn = $expectedOn;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function expectedOn(): \DateTimeImmutable
    {
        return $this->expectedOn;
    }
}
