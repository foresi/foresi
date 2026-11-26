<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Api;

use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Controller\ProfileController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A person as the API and MCP show them (schema Profile). The photo's address
 * needs a signed-in session, so a client shows it only inside Foresi.
 */
final readonly class ProfileJson
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @return array{id: string, email: string, display_name: string, language: string, photo_url: string|null}
     */
    public function of(User $user): array
    {
        $version = $user->photoVersion();

        return [
            'id' => $user->id(),
            'email' => $user->email(),
            'display_name' => $user->displayName(),
            'language' => $user->locale(),
            'photo_url' => null === $version ? null : $this->urls->generate(ProfileController::PHOTO_ROUTE, ['id' => $user->id(), 'v' => $version], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
