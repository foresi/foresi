<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

/**
 * What a signature provider returns once everyone signed. Designed, not built
 * (see SignatureProvider).
 */
final readonly class SignedFiles
{
    public function __construct(public string $signedPdf, public ?string $certificatePdf)
    {
    }
}
