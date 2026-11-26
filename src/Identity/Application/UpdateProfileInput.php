<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\User;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A person changes their own name, language or appearance (ADR 0032): a field left out
 * keeps its value. Every role may, guests too; it is always the token's person.
 */
#[Operation(
    name: 'identity.profile.update',
    method: 'PATCH',
    path: '/api/v1/me',
    tool: 'foresi_update_profile',
    scope: 'profile:write',
    title: 'Update my profile',
    description: 'Changes the name, the language or the appearance of the person this connection acts as and keeps what is not sent. The name is how everyone in the firm sees the person; the language is the one Foresi is shown in; the text size and the density are how large the text and how tall the rows are in the whole app, on every device. See foresi_whoami for the current values.',
    response: 'Profile',
)]
final readonly class UpdateProfileInput
{
    public function __construct(
        #[Field('The name everyone in the firm sees, such as "Jane Example".', maxLength: User::MAX_DISPLAY_NAME_LENGTH)]
        public string|Omitted $displayName = new Omitted(),
        #[Field('The language Foresi is shown in.', enum: User::LOCALES)]
        public string|Omitted $language = new Omitted(),
        #[Field('How large the text is in the whole app: small (13 px body text), standard (14 px) or large (16 px).', enum: User::TEXT_SIZES)]
        public string|Omitted $textSize = new Omitted(),
        #[Field('How tall the table rows are in the whole app, and with a mouse the fields and buttons: compact, normal or spacious.', enum: User::DENSITIES)]
        public string|Omitted $density = new Omitted(),
    ) {
    }
}
