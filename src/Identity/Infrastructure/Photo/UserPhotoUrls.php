<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Photo;

use Doctrine\DBAL\Connection;
use Foresi\Identity\Infrastructure\Controller\ProfileController;
use Foresi\Ui\Contract\PersonPhotos;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The addresses of the people's photos for every avatar of a page: who has a
 * photo is read once a request, in one query, so a list of a hundred tasks asks
 * the database once and not a hundred times. The address carries the version of
 * the photo, so a browser keeps it until the person changes it.
 */
final class UserPhotoUrls implements PersonPhotos, ResetInterface
{
    /** @var array<string, string>|null photo versions by user id */
    private ?array $versions = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function url(string $userId): ?string
    {
        $this->versions ??= $this->read();
        $version = $this->versions[$userId] ?? null;

        return null === $version ? null : $this->urls->generate(ProfileController::PHOTO_ROUTE, ['id' => $userId, 'v' => $version]);
    }

    /**
     * Forgets what it read, as after a person changed their photo.
     */
    public function reset(): void
    {
        $this->versions = null;
    }

    /**
     * @return array<string, string>
     */
    private function read(): array
    {
        $versions = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT id, photo_version FROM app_user WHERE photo_version IS NOT NULL') as $id => $version) {
            if (\is_string($version)) {
                $versions[(string) $id] = $version;
            }
        }

        return $versions;
    }
}
