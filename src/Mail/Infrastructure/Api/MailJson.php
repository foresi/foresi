<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Api;

use Foresi\Mail\Application\AddressView;

/**
 * An inbound address as the API shows it: never its token, and the full address
 * only in the answer that issued it.
 */
final class MailJson
{
    private function __construct()
    {
    }

    /**
     * @return array{id: string, purpose: string, label: string, masked_address: string, created_at: string, revoked_at: ?string, last_received_at: ?string}
     */
    public static function address(AddressView $view, \DateTimeZone $zone): array
    {
        return [
            'id' => $view->id,
            'purpose' => $view->purpose,
            'label' => $view->label,
            'masked_address' => $view->maskedAddress,
            'created_at' => $view->createdAt->setTimezone($zone)->format(\DATE_ATOM),
            'revoked_at' => $view->revokedAt?->setTimezone($zone)->format(\DATE_ATOM),
            'last_received_at' => $view->lastReceivedAt?->setTimezone($zone)->format(\DATE_ATOM),
        ];
    }
}
