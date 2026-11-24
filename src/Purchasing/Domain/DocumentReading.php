<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;

/**
 * The reading of a received document's file, one per document: the merged
 * values with the source of each, which of them were taken into the draft and
 * which were left as suggestions, and the warnings of applying them.
 *
 * It is asked for with the version of the document it may fill (the base
 * version): once the document has another version, a person has changed it,
 * and everything the reading found stays a suggestion. A failure keeps a short
 * code, never a text from a reader or a provider. It goes with its document.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchasing_document_reading')]
class DocumentReading
{
    /** No reader could read the file and none found anything. */
    public const string READER_FAILED = 'reader_failed';
    /** The stored original file is missing or cannot be read. */
    public const string ORIGINAL_UNREADABLE = 'original_unreadable';
    /** The job gave up after its retries. */
    public const string UNEXPECTED = 'unexpected';
    public const int MAX_CODE_LENGTH = 32;

    #[ORM\Column(type: 'string', length: 16, enumType: ReadingState::class)]
    private ReadingState $state;

    /** @var array<string, array{value: string|int|array<mixed>, source: string}> */
    #[ORM\Column(name: 'read_values', type: 'json')]
    private array $values = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $applied = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $suggested = [];

    #[ORM\Column(name: 'base_version', type: 'integer')]
    private int $baseVersion;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $warnings = [];

    // How many times the reading was asked for: on arrival and on each request since.
    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(type: 'string', length: self::MAX_CODE_LENGTH, nullable: true)]
    private ?string $failure;

    #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt;

    /**
     * Asked for when the document is made from a file.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: ReceivedDocument::class)]
        #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
        private readonly ReceivedDocument $document,
        int $baseVersion,
        \DateTimeImmutable $at,
    ) {
        $this->start($baseVersion, $at);
    }

    /**
     * Asks for the reading again, to fill the document at this version; what an
     * earlier reading found stays until this one finishes.
     */
    public function start(int $baseVersion, \DateTimeImmutable $at): void
    {
        $this->state = ReadingState::Reading;
        $this->baseVersion = $baseVersion;
        $this->requestedAt = $at;
        $this->finishedAt = null;
        $this->failure = null;
        ++$this->attempts;
    }

    /**
     * Read: values found, or none and no reader failed.
     *
     * @param list<ReadField> $applied   taken into the draft
     * @param list<ReadField> $suggested left for a person to take
     * @param list<string>    $warnings  codes of ReceivedDocumentWarning
     */
    public function finish(Reading $merged, array $applied, array $suggested, array $warnings, \DateTimeImmutable $at): void
    {
        $this->state = $merged->isEmpty() ? ReadingState::NothingFound : ReadingState::Read;
        $this->values = $merged->toArray();
        $this->applied = $this->keys($applied);
        $this->suggested = $this->keys($suggested);
        $this->warnings = $warnings;
        $this->failure = null;
        $this->finishedAt = $at;
    }

    /**
     * @param string $code one of the codes of this class, never a message
     */
    public function fail(string $code, \DateTimeImmutable $at): void
    {
        if ('' === $code || \strlen($code) > self::MAX_CODE_LENGTH || 1 !== preg_match('/^[a-z_]+$/D', $code)) {
            throw new \InvalidArgumentException('A failure is a short code.');
        }
        $this->state = ReadingState::Failed;
        $this->failure = $code;
        $this->finishedAt = $at;
    }

    public function documentId(): string
    {
        return $this->document->id();
    }

    public function state(): ReadingState
    {
        return $this->state;
    }

    public function isRunning(): bool
    {
        return ReadingState::Reading === $this->state;
    }

    public function reading(): Reading
    {
        return Reading::fromArray($this->values);
    }

    /**
     * @return list<string> keys of ReadField
     */
    public function applied(): array
    {
        return $this->applied;
    }

    /**
     * @return list<string> keys of ReadField
     */
    public function suggested(): array
    {
        return $this->suggested;
    }

    public function baseVersion(): int
    {
        return $this->baseVersion;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    public function requestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @param list<ReadField> $fields
     *
     * @return list<string>
     */
    private function keys(array $fields): array
    {
        return array_map(static fn (ReadField $field): string => $field->value, $fields);
    }
}
