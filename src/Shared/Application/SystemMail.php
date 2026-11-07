<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * One mail of the installation itself, such as an invitation: never sent through
 * a firm's server. The bodies are the content only; the layout adds the product
 * and the footer in the locale. The HTML body, when given, is trusted markup the
 * caller rendered. Queued as it is, so it holds no secret that outlives the mail.
 */
final readonly class SystemMail implements \JsonSerializable
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $text,
        public ?string $html,
        public string $locale,
        public ?string $organisation = null,
    ) {
    }

    /**
     * What a log may show of the mail: none of its addresses or content.
     *
     * @return array{locale: string}
     */
    public function jsonSerialize(): array
    {
        return ['locale' => $this->locale];
    }
}
