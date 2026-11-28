<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Banking\Contract\BankStatement;
use Foresi\Banking\Contract\MovementChannel;
use Foresi\Banking\Contract\MovementProvenance;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Contract\StatementSequence;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;

/**
 * A bank statement as it came in: a statement file or an answer of a bank's API,
 * with what it says of itself (the bank's id and number, the period, the balances)
 * and of its entries (how many, their sum), who sent it and how far it is trusted.
 * It never changes. Whether the statements of an account follow on is not stored
 * but read from them each time, so a statement that fills a gap closes it.
 *
 * A bank mail is not stored here: its message is.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bank_statement')]
#[ORM\Index(name: 'idx_bank_statement_account', columns: ['firm_bank_account_id', 'period_to'])]
class ReceivedStatement implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 16, enumType: MovementChannel::class)]
    private MovementChannel $channel;

    #[ORM\Column(name: 'source_id', type: 'string', length: 64)]
    private string $sourceId;

    #[ORM\Column(name: 'statement_ref', type: 'string', length: BankStatement::MAX_STATEMENT_ID, nullable: true)]
    private ?string $statementRef;

    #[ORM\Column(name: 'sequence_kind', type: 'string', length: 16, nullable: true)]
    private ?string $sequenceKind;

    #[ORM\Column(name: 'sequence_number', type: 'integer', nullable: true)]
    private ?int $sequenceNumber;

    #[ORM\Column(name: 'sequence_year', type: 'integer', nullable: true)]
    private ?int $sequenceYear;

    #[ORM\Column(name: 'period_from', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $periodFrom;

    #[ORM\Column(name: 'period_to', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'currency', length: 3, options: ['fixed' => true])]
    private Currency $currency;

    #[ORM\Column(name: 'opening_minor', type: 'bigint', nullable: true)]
    private ?int $openingMinor;

    #[ORM\Column(name: 'closing_minor', type: 'bigint', nullable: true)]
    private ?int $closingMinor;

    #[ORM\Column(name: 'entries_net_minor', type: 'bigint')]
    private int $entriesNetMinor;

    #[ORM\Column(name: 'entry_count', type: 'integer')]
    private int $entryCount;

    #[ORM\Column(type: 'string', length: 16, enumType: MovementTrust::class)]
    private MovementTrust $trust;

    #[ORM\Column(name: 'trust_basis', type: 'string', length: 32)]
    private string $trustBasis;

    #[ORM\Column(name: 'actor_id', type: 'guid', nullable: true)]
    private ?string $actorId;

    /**
     * @param string|null $fileSha256 sha256 of the file it was read from, in hex
     * @param string|null $fileName   the name of that file
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: FirmBankAccount::class)]
        #[ORM\JoinColumn(name: 'firm_bank_account_id', nullable: false, onDelete: 'RESTRICT')]
        private FirmBankAccount $account,
        BankStatement $statement,
        MovementProvenance $provenance,
        #[ORM\Column(name: 'file_sha256', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
        private ?string $fileSha256,
        #[ORM\Column(name: 'file_name', type: 'string', length: 255, nullable: true)]
        private ?string $fileName,
        #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $receivedAt,
    ) {
        if (MovementChannel::Mail === $provenance->channel) {
            throw new \InvalidArgumentException('A bank mail is stored as its message, not as a statement.');
        }
        if (null !== $fileSha256 && 1 !== preg_match('/^[0-9a-f]{64}$/D', $fileSha256)) {
            throw new \InvalidArgumentException('A file hash is a sha256 in lowercase hex.');
        }
        if (null !== $fileName && mb_strlen($fileName) > 255) {
            throw new \InvalidArgumentException('A file name is at most 255 characters.');
        }
        $this->id = Id::generate();
        $this->channel = $provenance->channel;
        $this->sourceId = $provenance->sourceId;
        $this->trust = $provenance->trust;
        $this->trustBasis = $provenance->basis;
        $this->actorId = $provenance->actorId;
        $this->statementRef = $statement->statementId;
        $this->sequenceKind = $statement->sequence?->kind;
        $this->sequenceNumber = $statement->sequence?->number;
        $this->sequenceYear = $statement->sequence?->year;
        $this->periodFrom = $statement->from;
        $this->periodTo = $statement->to;
        $this->currency = $statement->currency;
        $this->openingMinor = $statement->openingBalance?->minor();
        $this->closingMinor = $statement->closingBalance?->minor();
        $this->entriesNetMinor = $statement->net()->minor();
        $this->entryCount = \count($statement->entries);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function account(): FirmBankAccount
    {
        return $this->account;
    }

    public function channel(): MovementChannel
    {
        return $this->channel;
    }

    public function sourceId(): string
    {
        return $this->sourceId;
    }

    public function statementRef(): ?string
    {
        return $this->statementRef;
    }

    public function sequence(): ?StatementSequence
    {
        return null === $this->sequenceKind || null === $this->sequenceNumber ? null : StatementSequence::of($this->sequenceKind, $this->sequenceNumber, $this->sequenceYear);
    }

    public function periodFrom(): ?\DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function periodTo(): ?\DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function openingBalance(): ?Money
    {
        return null === $this->openingMinor ? null : Money::ofMinor($this->openingMinor, $this->currency);
    }

    public function closingBalance(): ?Money
    {
        return null === $this->closingMinor ? null : Money::ofMinor($this->closingMinor, $this->currency);
    }

    public function entriesNet(): Money
    {
        return Money::ofMinor($this->entriesNetMinor, $this->currency);
    }

    public function entryCount(): int
    {
        return $this->entryCount;
    }

    public function trust(): MovementTrust
    {
        return $this->trust;
    }

    public function trustBasis(): string
    {
        return $this->trustBasis;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function fileSha256(): ?string
    {
        return $this->fileSha256;
    }

    public function fileName(): ?string
    {
        return $this->fileName;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
