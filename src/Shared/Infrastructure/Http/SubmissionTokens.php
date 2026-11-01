<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * One-time tokens for forms that create something. A form carries the token it
 * was shown with; once the submission succeeded, the token remembers what it
 * created, so a second POST with it (a double click, a reload of the result)
 * leads to the same record instead of creating another one.
 */
final readonly class SubmissionTokens
{
    private const string KEY = 'party_submission_tokens';
    private const int KEEP = 30;
    private const string UNUSED = '';

    public function __construct(private RequestStack $requests)
    {
    }

    public function issue(): string
    {
        $tokens = $this->tokens();
        $token = bin2hex(random_bytes(16));
        $tokens[$token] = self::UNUSED;
        // Only unused tokens go: a token that created something must keep leading to it.
        $unused = array_filter($tokens, static fn (string $id): bool => self::UNUSED === $id);
        $this->store(array_diff_key($tokens, \array_slice($unused, 0, -self::KEEP, true)));

        return $token;
    }

    public function isIssued(string $token): bool
    {
        return \array_key_exists($token, $this->tokens());
    }

    /**
     * The id of the record the token created, if it created one already.
     */
    public function createdId(string $token): ?string
    {
        $id = $this->tokens()[$token] ?? self::UNUSED;

        return self::UNUSED === $id ? null : $id;
    }

    public function complete(string $token, string $id): void
    {
        $tokens = $this->tokens();
        if (\array_key_exists($token, $tokens)) {
            $tokens[$token] = $id;
            $this->store($tokens);
        }
    }

    /**
     * A session from before ids were UUIDs may hold whole numbers: an unused
     * token (0) stays usable, and an old id leads nowhere any more, so it is dropped.
     *
     * @return array<string, string>
     */
    private function tokens(): array
    {
        $stored = $this->requests->getSession()->get(self::KEY, []);
        $tokens = [];
        if (\is_array($stored)) {
            foreach ($stored as $token => $id) {
                if (\is_string($token) && (\is_string($id) || 0 === $id)) {
                    $tokens[$token] = 0 === $id ? self::UNUSED : $id;
                }
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, string> $tokens
     */
    private function store(array $tokens): void
    {
        $this->requests->getSession()->set(self::KEY, $tokens);
    }
}
