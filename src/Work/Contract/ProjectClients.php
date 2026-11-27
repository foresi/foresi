<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * Port: the client of a project, a contact of the address book. Work declares it and
 * answers it in its Contacts integration, so it exists only where the address book is
 * installed. Without it a project has no client: no screen shows or picks one, the lists
 * have no client field, column or grouping, and a write that names a client is refused,
 * while the reads still return the client a project keeps. Inject it nullable.
 */
interface ProjectClients
{
    /**
     * The address the client picker reads its choices from, as a Ui combobox takes it.
     */
    public function pickerUrl(): string;

    /**
     * The name of a contact, or null for none.
     */
    public function name(string $id): ?string;

    /**
     * The page of a contact in the address book, or null for none.
     */
    public function pageUrl(string $id): ?string;
}
