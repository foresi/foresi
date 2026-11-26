<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Infrastructure\Target;

use Foresi\Import\Application\Target\TargetExtension;
use Foresi\Import\Contract\FieldRecordTarget;
use Foresi\Import\Contract\ImportTarget;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Contract\Field\Fields;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wraps a target whose records take custom fields in CustomFieldTarget; leaves any
 * other target as it is.
 */
final readonly class CustomFieldTargets implements TargetExtension
{
    public function __construct(private Fields $fields, private ReferenceNames $references, private TranslatorInterface $translator)
    {
    }

    public function extend(ImportTarget $target): ImportTarget
    {
        return $target instanceof FieldRecordTarget ? new CustomFieldTarget($target, $target->fieldRecord(), $this->fields, $this->references, $this->translator) : $target;
    }
}
