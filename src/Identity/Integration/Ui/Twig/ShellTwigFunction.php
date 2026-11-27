<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Twig;

use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\Organisation;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Identity\Integration\Ui\Controller\ProfileController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Attribute\AsTwigFunction;

final readonly class ShellTwigFunction
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private UserRepository $users,
        private MembershipDirectory $memberships,
        private UrlGeneratorInterface $urls,
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
            $user->locale(),
            $this->otherLocale($user->locale()),
            $organisation instanceof Organisation,
            $organisation,
            null === $user->photoVersion() ? null : $this->urls->generate(ProfileController::PHOTO_ROUTE, ['id' => $user->id(), 'v' => $user->photoVersion()]),
        );
    }

    /**
     * The person's appearance for the page's <body> (data-text-size, data-density): the defaults
     * for anonymous requests. The user comes from the identity map the shell reads too, so it
     * costs no query of its own.
     *
     * @return array{textSize: string, density: string}
     */
    #[AsTwigFunction('appearance')]
    public function appearance(): array
    {
        $securityUser = $this->tokens->getToken()?->getUser();
        $user = $securityUser instanceof SecurityUser ? $this->users->findById($securityUser->id()) : null;

        return $user instanceof User
            ? ['textSize' => $user->textSize(), 'density' => $user->density()]
            : ['textSize' => User::TEXT_STANDARD, 'density' => User::DENSITY_NORMAL];
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
