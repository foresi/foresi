<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mcp;

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

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(string $message): self
    {
        return new self(self::NOT_FOUND, $message);
    }

    public static function invalidArgument(string $message): self
    {
        return new self(self::INVALID_ARGUMENT, $message);
    }
}
