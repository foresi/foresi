<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Tenancy\Domain\TenantId;

/**
 * The logo and stamp images of the firms, per tenant and outside the web root.
 * Files are named after their content and never deleted, so an image a document
 * froze stays when the firm changes it. The stamp holds a signature: it leaves
 * this store only inside a generated document, and no route serves it.
 */
interface FirmImages
{
    /**
     * @return string the name of the image: its SHA-256, then .png or .jpg
     *
     * @throws \InvalidArgumentException when the bytes are no PNG or JPEG
     */
    public function store(TenantId $tenant, string $bytes): string;

    /**
     * @throws \InvalidArgumentException when it cannot be the name of an image
     * @throws \RuntimeException         when the image is not stored
     */
    public function path(TenantId $tenant, string $name): string;
}
