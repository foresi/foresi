<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: the public address of a sent document, as the customer opens it from the
 * mail. It is built on the installation's configured base, never on the host of
 * the request that asked for the send.
 */
interface DocumentLinks
{
    public function url(string $token): string;

    /**
     * The absolute address of a page of the application on the same base, for a
     * mail that sends staff into the app, from a run in the worker too.
     *
     * @param array<string, string> $parameters
     */
    public function page(string $route, array $parameters = []): string;

    /**
     * False when the base is a loopback or localhost address the customer could
     * not open, unless the installation allows that, as tests do.
     */
    public function allowed(): bool;

    /**
     * False for a base that is not https where the installation runs for real.
     */
    public function secure(): bool;
}
