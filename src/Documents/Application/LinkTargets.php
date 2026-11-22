<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Contract\DocumentLinkTarget;
use Foresi\Documents\Contract\RecordLink;
use Foresi\Shared\Domain\Id;

/**
 * The records documents can link to, offered by other modules.
 */
final readonly class LinkTargets
{
    /**
     * @param iterable<DocumentLinkTarget> $targets
     */
    public function __construct(private iterable $targets)
    {
    }

    /**
     * Reads the links of an input and checks each: a kind some module offers,
     * and a record that exists.
     *
     * @param list<mixed> $links
     *
     * @return list<array{kind: string, id: string}>
     *
     * @throws DocumentsRefused invalid
     */
    public function check(array $links): array
    {
        $checked = [];
        foreach ($links as $at => $link) {
            $kind = \is_array($link) ? ($link['kind'] ?? null) : null;
            $id = \is_array($link) ? ($link['id'] ?? null) : null;
            if (!\is_string($kind) || !\is_string($id) || !Id::isValid($id)) {
                throw DocumentsRefused::invalid('link_invalid', 'links', \sprintf('Link %d is not an object {kind, id} with a valid id.', $at + 1));
            }
            $target = $this->target($kind) ?? throw DocumentsRefused::invalid('link_kind_unknown', 'links', \sprintf('Documents cannot link to records of kind "%s"; the kinds offered are: %s.', $kind, [] === $this->kinds() ? 'none' : implode(', ', $this->kinds())), ['kind' => $kind]);
            if (!$target->exists($kind, $id)) {
                throw DocumentsRefused::invalid('link_target_unknown', 'links', \sprintf('There is no %s with the id %s.', $kind, $id), ['kind' => $kind, 'id' => $id]);
            }
            $checked[] = ['kind' => $kind, 'id' => $id];
        }

        return $checked;
    }

    /**
     * What a link shows: the record's name and screen, or unavailable when the
     * record is gone or no module offers its kind any more.
     */
    public function describe(RecordLink $link): LinkView
    {
        $target = $this->target($link->kind);
        if (!$target instanceof DocumentLinkTarget || !$target->exists($link->kind, $link->id)) {
            return new LinkView($link->kind, $link->id, false, null, null);
        }

        return new LinkView($link->kind, $link->id, true, $target->label($link->kind, $link->id), $target->url($link->kind, $link->id));
    }

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        $kinds = [];
        foreach ($this->targets as $target) {
            array_push($kinds, ...$target->kinds());
        }
        sort($kinds);

        return array_values(array_unique($kinds));
    }

    private function target(string $kind): ?DocumentLinkTarget
    {
        foreach ($this->targets as $target) {
            if (\in_array($kind, $target->kinds(), true)) {
                return $target;
            }
        }

        return null;
    }
}
