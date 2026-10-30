<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Twig;

use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Application\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Attribute\AsTwigFunction;

final readonly class ShellTwigFunction
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private UserRepository $users,
        private MembershipDirectory $memberships,
        private TenantContext $context,
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

        $organisations = $this->memberships->organisationsOf($user->id());
        $tenantActive = $this->context->has();
        $organisation = null;
        if ($tenantActive) {
            $current = $this->context->current();
            foreach ($organisations as $candidate) {
                if ($candidate->id->equals($current)) {
                    $organisation = $candidate;
                }
            }
        }

        return new ShellView(
            $user->displayName(),
            $user->email(),
            $this->initials($user->displayName()),
            $user->locale(),
            $this->otherLocale($user->locale()),
            $tenantActive,
            $organisation,
            $organisations,
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

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, \PREG_SPLIT_NO_EMPTY);
        $words = false === $words ? [] : $words;
        $letters = array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), \array_slice($words, 0, 2));

        return implode('', $letters);
    }
}
