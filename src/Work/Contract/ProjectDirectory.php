<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * The projects as other modules read them, without Work's own classes: Sales
 * for the project an hourly deal line links to. It reads as the person signed
 * in, so a project they do not see is not there, as everywhere in Work.
 */
interface ProjectDirectory
{
    /**
     * @return ProjectSummary|null an archived project too, flagged; null for an unknown id or one the person does not see
     */
    public function find(string $id): ?ProjectSummary;

    /**
     * @param string|null $clientId a contact of the address book, or null for the projects of no client
     *
     * @return list<ProjectSummary> the projects of the client the person sees, by name; the archived ones too unless active only
     */
    public function ofClient(?string $clientId, bool $activeOnly = true): array;
}
