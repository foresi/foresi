<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Support\Mail;

use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Contract\NotAccepted;

/**
 * A purpose of the Mail tests: records what it was handed, and refuses or fails
 * when a test says so. The record is static, as the container builds a fresh
 * instance in every kernel; reset() it in a test's setUp.
 */
final class RecordingPurpose implements InboundPurpose
{
    public const string KEY = 'test';
    public const string RESHOWABLE_KEY = 'test_reshowable';

    /** @var list<array{string, string}> message id and address id, in order */
    private static array $accepted = [];
    private static ?\Throwable $failure = null;

    public function __construct(private readonly string $key, private readonly bool $reshowable)
    {
    }

    public static function reset(): void
    {
        self::$accepted = [];
        self::$failure = null;
    }

    /**
     * The next messages are refused (NotAccepted) or fail with the error given; null takes them again.
     */
    public static function failWith(?\Throwable $failure): void
    {
        self::$failure = $failure;
    }

    /**
     * @return list<array{string, string}>
     */
    public static function accepted(): array
    {
        return self::$accepted;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Test';
    }

    public function ownerRequired(): bool
    {
        return false;
    }

    public function reshowable(): bool
    {
        return $this->reshowable;
    }

    public function accept(string $messageId, string $addressId): void
    {
        if (self::$failure instanceof \Throwable) {
            throw self::$failure;
        }
        self::$accepted[] = [$messageId, $addressId];
    }

    public static function refusal(): NotAccepted
    {
        return new NotAccepted('Refused by the test.');
    }
}
