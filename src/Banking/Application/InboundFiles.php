<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * The raw inbound messages, kept as .eml files per tenant, outside the web
 * root. Back them up with the database: a message without its file cannot be
 * processed again.
 */
interface InboundFiles
{
    /**
     * @return string the key to read it by
     */
    public function store(string $tenantId, string $messageId, string $raw): string;

    /**
     * @throws \RuntimeException when the file is missing, of another tenant or changed
     */
    public function read(string $tenantId, string $key, string $sha256): string;

    /**
     * Removes the file of a message that was never stored; nothing when there is none.
     */
    public function remove(string $tenantId, string $messageId): void;
}
