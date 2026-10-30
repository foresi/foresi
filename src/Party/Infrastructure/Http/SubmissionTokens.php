<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Http;

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

    public function __construct(private RequestStack $requests)
    {
    }

    public function issue(): string
    {
        $tokens = $this->tokens();
        $token = bin2hex(random_bytes(16));
        $tokens[$token] = 0;
        $this->store(\array_slice($tokens, -self::KEEP, null, true));

        return $token;
    }

    public function isIssued(string $token): bool
    {
        return \array_key_exists($token, $this->tokens());
    }

    /**
     * The id of the record the token created, if it created one already.
     */
    public function createdId(string $token): ?int
    {
        $id = $this->tokens()[$token] ?? 0;

        return $id > 0 ? $id : null;
    }

    public function complete(string $token, int $id): void
    {
        $tokens = $this->tokens();
        if (\array_key_exists($token, $tokens)) {
            $tokens[$token] = $id;
            $this->store($tokens);
        }
    }

    /**
     * @return array<string, int>
     */
    private function tokens(): array
    {
        $stored = $this->requests->getSession()->get(self::KEY, []);
        $tokens = [];
        if (\is_array($stored)) {
            foreach ($stored as $token => $id) {
                if (\is_string($token) && \is_int($id)) {
                    $tokens[$token] = $id;
                }
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, int> $tokens
     */
    private function store(array $tokens): void
    {
        $this->requests->getSession()->set(self::KEY, $tokens);
    }
}
