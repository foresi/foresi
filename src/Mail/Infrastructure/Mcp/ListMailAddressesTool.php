<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Mcp;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\AddressReads;
use Foresi\Mail\Application\AddressView;
use Foresi\Mail\Application\MailRefused;
use Foresi\Mail\Infrastructure\Api\MailJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

final readonly class ListMailAddressesTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private AddressReads $reads, private InstanceClock $clock)
    {
    }

    public function name(): string
    {
        return 'foresi_list_mail_addresses';
    }

    public function title(): string
    {
        return 'List my inbound mail addresses';
    }

    public function scope(): string
    {
        return 'mail_addresses:read';
    }

    public function description(): string
    {
        return 'Returns your own inbound mail addresses, oldest first, revoked ones too: {addresses} with the id, purpose, label, masked_address (the address with its secret part left out), created_at, revoked_at and last_received_at of each. '
            .'The full address is shown only once, by foresi_create_mail_address and foresi_replace_mail_address. Use an id with foresi_rename_mail_address, foresi_revoke_mail_address or foresi_replace_mail_address.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'purpose' => ['type' => 'string', 'description' => 'Only the addresses of this purpose.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $purpose = $arguments['purpose'] ?? null;
        try {
            $addresses = $this->reads->mine(\is_string($purpose) ? $purpose : null);
        } catch (MailRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $zone = $this->clock->timezone();
        $count = \count($addresses);

        return new ToolOutput(
            ['addresses' => array_map(static fn (AddressView $view): array => MailJson::address($view, $zone), $addresses)],
            1 === $count ? '1 inbound address.' : \sprintf('%d inbound addresses, oldest first.', $count),
        );
    }
}
