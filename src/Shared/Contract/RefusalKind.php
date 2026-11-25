<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract;

/**
 * What sort of refusal a use case makes, which decides how every surface
 * answers it (ADR 0032): REST with a status, MCP with a code.
 */
enum RefusalKind: string
{
    /** A value of the input cannot be used: REST 400 invalid_parameter, MCP invalid_argument. */
    case Invalid = 'invalid';
    /** The person may not do this: 403. */
    case Forbidden = 'forbidden';
    /** No such record, or not one the person may see: 404. */
    case NotFound = 'not_found';
    /** The data is in a state that does not allow this, such as a duplicate: 409. */
    case Conflict = 'conflict';
    /** The installation cannot do this now, and only its operator can change that: 503. */
    case Unavailable = 'unavailable';

    public function status(): int
    {
        return match ($this) {
            self::Invalid => 400,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::Conflict => 409,
            self::Unavailable => 503,
        };
    }
}
