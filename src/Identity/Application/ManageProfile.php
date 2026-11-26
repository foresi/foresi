<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\User;
use Foresi\Shared\Contract\Operation\Omitted;

/**
 * What a person changes on their own account: the name, the language, the appearance
 * (text size and density) and the photo.
 * Each person changes only their own, so the user id is always the signed-in one.
 */
final readonly class ManageProfile
{
    public function __construct(private UserRepository $users, private UserPhotos $photos)
    {
    }

    /**
     * @throws ProfileRefused
     */
    public function update(string $userId, UpdateProfileInput $input): User
    {
        $user = $this->user($userId);
        if (!$input->displayName instanceof Omitted) {
            $name = trim($input->displayName);
            if ('' === $name) {
                throw ProfileRefused::invalid(ProfileRefused::NAME_REQUIRED, 'displayName', 'A name is required.');
            }
            if (mb_strlen($name) > User::MAX_DISPLAY_NAME_LENGTH) {
                throw ProfileRefused::invalid(ProfileRefused::NAME_TOO_LONG, 'displayName', \sprintf('A name has at most %d characters.', User::MAX_DISPLAY_NAME_LENGTH));
            }
            $user->rename($name);
        }
        if (!$input->language instanceof Omitted) {
            if (!\in_array($input->language, User::LOCALES, true)) {
                throw ProfileRefused::invalid(ProfileRefused::UNSUPPORTED_LANGUAGE, 'language', \sprintf('The language is one of: %s.', implode(', ', User::LOCALES)));
            }
            $user->changeLocale($input->language);
        }
        if (!$input->textSize instanceof Omitted) {
            if (!\in_array($input->textSize, User::TEXT_SIZES, true)) {
                throw ProfileRefused::invalid(ProfileRefused::UNSUPPORTED_TEXT_SIZE, 'textSize', \sprintf('The text size is one of: %s.', implode(', ', User::TEXT_SIZES)));
            }
            $user->changeTextSize($input->textSize);
        }
        if (!$input->density instanceof Omitted) {
            if (!\in_array($input->density, User::DENSITIES, true)) {
                throw ProfileRefused::invalid(ProfileRefused::UNSUPPORTED_DENSITY, 'density', \sprintf('The density is one of: %s.', implode(', ', User::DENSITIES)));
            }
            $user->changeDensity($input->density);
        }
        $this->users->save($user);

        return $user;
    }

    /**
     * Replaces the photo, if there is one.
     *
     * @throws ProfileRefused when the upload is no usable photo
     */
    public function changePhoto(string $userId, string $bytes): User
    {
        $user = $this->user($userId);
        $this->photos->store($user->id(), $bytes);
        $user->changePhoto();
        $this->users->save($user);

        return $user;
    }

    public function removePhoto(string $userId): User
    {
        $user = $this->user($userId);
        $this->photos->remove($user->id());
        $user->removePhoto();
        $this->users->save($user);

        return $user;
    }

    private function user(string $userId): User
    {
        return $this->users->findById($userId) ?? throw ProfileRefused::unknownUser();
    }
}
