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
 *
 * Headers are the few a mail of a list needs, such as List-Unsubscribe and
 * List-Unsubscribe-Post (RFC 2369, RFC 8058), by name; never an address header.
 */
final readonly class SystemMail implements \JsonSerializable
{
    /** The headers a system mail may carry. */
    public const array HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post'];

    /**
     * @param array<string, string> $headers
     *
     * @throws \InvalidArgumentException when a header is not one a system mail carries
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $text,
        public ?string $html,
        public string $locale,
        public ?string $organisation = null,
        public array $headers = [],
    ) {
        foreach (array_keys($headers) as $name) {
            if (!\in_array($name, self::HEADERS, true)) {
                throw new \InvalidArgumentException(sprintf('A system mail does not carry the header "%s".', $name));
            }
        }
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
