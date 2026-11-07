<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Marks a controller (or one action) an external person may reach. Every other
 * controller is for the staff alone: the external access guard turns external
 * people away before it runs (docs/adr/0025-roles-owner-member-external.md).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class OpenToExternal
{
}
