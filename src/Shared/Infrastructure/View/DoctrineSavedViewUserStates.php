<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Application\View\SavedViewUserStates;
use Foresi\Shared\Domain\View\SavedViewUserState;

final readonly class DoctrineSavedViewUserStates implements SavedViewUserStates
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function display(string $viewId, string $userId): ?array
    {
        return $this->state($viewId, $userId)?->display();
    }

    public function remember(string $viewId, string $userId, array $display): void
    {
        // Only bookkeeping: nothing reads when a person last changed their display.
        $now = new \DateTimeImmutable();
        $state = $this->state($viewId, $userId);
        if (!$state instanceof SavedViewUserState) {
            $this->em->persist(new SavedViewUserState($viewId, $userId, $display, $now));
        } elseif ($state->display() !== $display) {
            $state->redisplay($display, $now);
        } else {
            return;
        }
        $this->em->flush();
    }

    public function forget(string $viewId, string $userId): void
    {
        $state = $this->state($viewId, $userId);
        if ($state instanceof SavedViewUserState) {
            $this->em->remove($state);
            $this->em->flush();
        }
    }

    private function state(string $viewId, string $userId): ?SavedViewUserState
    {
        return $this->em->find(SavedViewUserState::class, ['viewId' => $viewId, 'userId' => $userId]);
    }
}
