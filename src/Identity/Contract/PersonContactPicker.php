<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Contract;

/**
 * Port: the picker of the contact a person represents, on the people screens. The
 * address book serves it. With it, the invitation and the edit form of a person offer
 * the contact field and the people lists show the contact column; without it there is
 * neither, and the contact a person already has stays as it is (ADR 0049). Identity
 * injects it nullable.
 */
interface PersonContactPicker
{
    /**
     * Where the field searches contacts: a GET with the text typed in `q`, open to the
     * owner, that answers `{"results": [{"id": …, "label": …, "hint": …}]}`.
     */
    public function searchUrl(): string;
}
