<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class Pagination
{
    public int $page = 1;
    public int $pages = 1;
    public string $baseUrl = '';
    public string $param = 'page';

    #[PostMount]
    public function validate(): void
    {
        Style::href($this->baseUrl);
        $this->page = max(1, min($this->page, $this->pages));
    }

    public function url(int $page): string
    {
        return $this->baseUrl.(str_contains($this->baseUrl, '?') ? '&' : '?').$this->param.'='.$page;
    }

    /**
     * Page numbers with null standing for a collapsed gap.
     *
     * @return list<int|null>
     */
    public function items(): array
    {
        $numbers = array_values(array_unique(array_filter(
            [1, $this->page - 1, $this->page, $this->page + 1, $this->pages],
            fn (int $n): bool => $n >= 1 && $n <= $this->pages,
        )));
        sort($numbers);

        $items = [];
        $previous = 0;
        foreach ($numbers as $number) {
            if ($number - $previous > 1) {
                $items[] = null;
            }
            $items[] = $number;
            $previous = $number;
        }

        return $items;
    }
}
