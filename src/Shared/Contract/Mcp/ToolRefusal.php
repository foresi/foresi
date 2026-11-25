<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Mcp;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * A tool call that cannot be answered as asked. The client gets it as an
 * error result, not a protocol error, so the model can read why and try
 * again: a stable code and a message written for the model. The message must
 * not quote text written by third parties.
 */
final class ToolRefusal extends \RuntimeException
{
    /** No record with this id, or not one the token may see. */
    public const string NOT_FOUND = 'not_found';
    /** An argument is well formed but not usable, such as an unknown cursor. */
    public const string INVALID_ARGUMENT = 'invalid_argument';
    /** The token does not have the scope the tool needs. */
    public const string INSUFFICIENT_SCOPE = 'insufficient_scope';
    /** The token's person may not read this, such as a guest of the organisation. */
    public const string FORBIDDEN = 'forbidden';
    /** The record is in a state that does not allow this, such as a disabled endpoint. */
    public const string CONFLICT = 'conflict';
    /** The installation cannot do this yet, and only its operator can change that. */
    public const string UNAVAILABLE = 'unavailable';
    /** The token spent its budget of writes; try again later. */
    public const string RATE_LIMITED = 'rate_limited';

    /**
     * @param array<string, scalar|list<scalar>|null> $details what a program can act on, such as the ids of duplicates
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    /**
     * What a use case refuses through the shared contract (ADR 0032), as MCP answers it:
     * an invalid value is invalid_argument with the parameter and the reason, everything
     * else its reason as the code.
     */
    public static function of(Refused $refused): self
    {
        $field = $refused->field();

        return RefusalKind::Invalid === $refused->kind()
            ? new self(self::INVALID_ARGUMENT, $refused->getMessage(), ['parameter' => null === $field ? null : self::wireName($field), 'reason' => $refused->reason()] + $refused->details())
            : new self($refused->reason(), $refused->getMessage(), $refused->details());
    }

    /**
     * The name of an input field as a client sends it: displayName is display_name.
     */
    private static function wireName(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    public static function notFound(string $message): self
    {
        return new self(self::NOT_FOUND, $message);
    }

    public static function invalidArgument(string $message): self
    {
        return new self(self::INVALID_ARGUMENT, $message);
    }

    public static function forbidden(string $message): self
    {
        return new self(self::FORBIDDEN, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(self::CONFLICT, $message);
    }

    public static function unavailable(string $message): self
    {
        return new self(self::UNAVAILABLE, $message);
    }
}
