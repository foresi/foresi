<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Com\Tecnick\Barcode\Barcode;

/**
 * The enrolment QR code as inline SVG, drawn on the server: the secret never
 * leaves for a third-party QR service.
 */
final readonly class TotpQrCode
{
    public function svg(#[\SensitiveParameter] string $provisioningUri): string
    {
        return new Barcode()->getBarcodeObj('QRCODE,M', $provisioningUri, -5, -5, 'black', [-4, -4, -4, -4])->setBackgroundColor('white')->getInlineSvgCode();
    }
}
