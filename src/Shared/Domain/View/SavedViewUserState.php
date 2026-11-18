<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain\View;

use Doctrine\ORM\Mapping as ORM;

/**
 * What one person last did on one view, kept for them alone (spec D7, D-display-3): the
 * display they last used on it, which nobody saved into the view. The per-person pin and
 * order of the views come here later (phase 4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'saved_view_user_state')]
class SavedViewUserState
{
    /**
     * @param array<array-key, mixed> $display the display payload of spec D-display-2
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'view_id', type: 'guid')]
        private string $viewId,
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'guid')]
        private string $userId,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        private array $display,
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<array-key, mixed> $display
     */
    public function redisplay(array $display, \DateTimeImmutable $now): void
    {
        $this->display = $display;
        $this->updatedAt = $now;
    }

    public function viewId(): string
    {
        return $this->viewId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function display(): array
    {
        return $this->display;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
