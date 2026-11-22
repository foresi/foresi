<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Domain\DocumentConflict;
use Foresi\Documents\Domain\InvalidField;

/**
 * Turns what the domain refuses into the refusals of the use cases.
 */
final class DocumentsInvalid
{
    /**
     * A refusal of the domain that names its field (InvalidField) is reported at
     * that field, any other at the given one.
     *
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     *
     * @throws DocumentsRefused
     */
    public static function unless(string $field, callable $change): mixed
    {
        try {
            return $change();
        } catch (InvalidField $invalid) {
            throw DocumentsRefused::invalid('invalid', $invalid->field, $invalid->getMessage());
        } catch (\InvalidArgumentException $invalid) {
            throw DocumentsRefused::invalid('invalid', $field, $invalid->getMessage());
        } catch (DocumentConflict $conflict) {
            throw DocumentsRefused::conflict($conflict->reason, $conflict->getMessage());
        }
    }
}
