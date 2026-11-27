<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Api;

use Foresi\Identity\Domain\User;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A person as the API and MCP show them (schema Profile). The photo's address
 * needs a signed-in session, so a client shows it only inside Foresi. The
 * photo is served by the profile screens (Integration/Ui); without Ui there is
 * no address and photo_url is null.
 */
final readonly class ProfileJson
{
    public const string PHOTO_ROUTE = 'app_user_photo';

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @return array{id: string, email: string, display_name: string, language: string, text_size: string, density: string, photo_url: string|null}
     */
    public function of(User $user): array
    {
        $version = $user->photoVersion();

        return [
            'id' => $user->id(),
            'email' => $user->email(),
            'display_name' => $user->displayName(),
            'language' => $user->locale(),
            'text_size' => $user->textSize(),
            'density' => $user->density(),
            'photo_url' => null === $version ? null : $this->photoUrl($user->id(), $version),
        ];
    }

    private function photoUrl(string $id, string $version): ?string
    {
        try {
            return $this->urls->generate(self::PHOTO_ROUTE, ['id' => $id, 'v' => $version], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
