<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Application\Target;

use Foresi\Import\Contract\FieldRecordTarget;
use Foresi\Import\Contract\ImportTarget;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Contract\Field\Fields;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * All import targets, found by their key. A target whose records take custom
 * fields comes wrapped in CustomFieldTarget, which adds them.
 */
final class TargetRegistry
{
    /** @var array<string, ImportTarget> */
    private array $targets = [];

    /**
     * @param iterable<ImportTarget> $targets
     */
    public function __construct(
        iterable $targets,
        ?Fields $fields = null,
        ?ReferenceNames $references = null,
        ?TranslatorInterface $translator = null,
    ) {
        foreach ($targets as $target) {
            if ($target instanceof FieldRecordTarget && $fields instanceof Fields && $translator instanceof TranslatorInterface) {
                $target = new CustomFieldTarget($target, $target->fieldRecord(), $fields, $references ?? new ReferenceNames(), $translator);
            }
            $this->targets[$target->key()] = $target;
        }
    }

    public function get(string $key): ImportTarget
    {
        return $this->targets[$key] ?? throw new \InvalidArgumentException(\sprintf('Unknown import target "%s".', $key));
    }

    /**
     * @return list<ImportTarget>
     */
    public function all(): array
    {
        return array_values($this->targets);
    }
}
