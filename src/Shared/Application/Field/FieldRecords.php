<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Contract\Field\RecordExtension;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The record kinds that take custom fields, of the installed modules.
 */
final class FieldRecords
{
    /** @var array<string, FieldRecord>|null */
    private ?array $byKind = null;

    /**
     * @param iterable<FieldRecord> $records
     */
    public function __construct(
        #[AutowireIterator(FieldRecord::TAG)]
        private readonly iterable $records,
    ) {
    }

    /**
     * @return array<string, FieldRecord> by record kind
     */
    public function all(): array
    {
        if (null === $this->byKind) {
            $this->byKind = [];
            foreach ($this->records as $record) {
                if (isset($this->byKind[$record->record()])) {
                    throw new \LogicException(\sprintf('Two modules declare the record kind "%s".', $record->record()));
                }
                $this->byKind[$record->record()] = $record;
            }
        }

        return $this->byKind;
    }

    public function get(string $record): ?FieldRecord
    {
        return $this->all()[$record] ?? null;
    }

    /**
     * The record kind whose list this is, and the alias of its row in the list's query.
     *
     * @return array{FieldRecord, string}|null
     */
    public function ofList(string $list): ?array
    {
        foreach ($this->all() as $record) {
            $alias = $record->lists()[$list] ?? null;
            if (null !== $alias) {
                return [$record, $alias];
            }
        }

        return null;
    }

    /**
     * The record kind an extension entity adds fields to.
     */
    public function ofExtension(RecordExtension $extension): ?FieldRecord
    {
        foreach ($this->all() as $record) {
            if (is_a($extension, $record->extension())) {
                return $record;
            }
        }

        return null;
    }
}
