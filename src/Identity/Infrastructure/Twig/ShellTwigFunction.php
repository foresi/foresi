<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Twig;

use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\Organisation;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Ui\Avatar;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Attribute\AsTwigFunction;

final readonly class ShellTwigFunction
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private UserRepository $users,
        private MembershipDirectory $memberships,
    ) {
    }

    /**
     * Null for anonymous requests, which have no shell.
     */
    #[AsTwigFunction('shell')]
    public function view(): ?ShellView
    {
        $securityUser = $this->tokens->getToken()?->getUser();
        if (!$securityUser instanceof SecurityUser) {
            return null;
        }
        $user = $this->users->findById($securityUser->id());
        if (!$user instanceof User) {
            return null;
        }

        $organisation = $this->memberships->organisationOf($user->id());

        return new ShellView(
            $user->displayName(),
            $user->email(),
            Avatar::initials($user->displayName()),
            $user->locale(),
            $this->otherLocale($user->locale()),
            $organisation instanceof Organisation,
            $organisation,
        );
    }

    private function otherLocale(string $locale): string
    {
        foreach (User::LOCALES as $candidate) {
            if ($candidate !== $locale) {
                return $candidate;
            }
        }

        return $locale;
    }
}
