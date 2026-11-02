<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * The inbound addresses of this server, built from one template such as
 * "bank+{token}@example.com" (env BANK_INBOX_ADDRESS). The template holds the
 * placeholder once, and the result is an e-mail address. An empty or malformed
 * template means bank mail is not set up here, and no address is handed out.
 */
final readonly class InboundAddresses
{
    public const string PLACEHOLDER = '{token}';

    private string $template;

    public function __construct(string $template)
    {
        $this->template = trim($template);
    }

    public function isConfigured(): bool
    {
        if (1 !== substr_count($this->template, self::PLACEHOLDER)) {
            return false;
        }

        return false !== filter_var(str_replace(self::PLACEHOLDER, str_repeat('a', InboundToken::LENGTH), $this->template), \FILTER_VALIDATE_EMAIL);
    }

    /**
     * @throws AddressRefused with NotConfigured
     */
    public function addressFor(string $token): string
    {
        if (!$this->isConfigured()) {
            throw new AddressRefused(AddressRefusal::NotConfigured);
        }

        return str_replace(self::PLACEHOLDER, $token, $this->template);
    }

    /**
     * The text with the token of every address made from the template cut out, as
     * "bank+…@example.com": a mail may quote its own address, such as a forwarding
     * confirmation does, and only the owner of a firm sees the address. An address
     * in a link may be URL-encoded ("bank%2B…%40example.com"), and one in a long
     * line wrapped by a quoted-printable soft break ("=" at the end of a line),
     * which is taken out first, so the token is masked however it is written.
     */
    public function masked(string $text): string
    {
        if (!$this->isConfigured()) {
            return $text;
        }
        $text = preg_replace('/=\r?\n/', '', $text) ?? $text;
        [$before, $after] = explode(self::PLACEHOLDER, $this->template, 2);
        $pattern = '/('.$this->written($before).')['.InboundToken::ALPHABET.']{'.InboundToken::LENGTH.'}('.$this->written($after).')/i';

        return preg_replace($pattern, "\$1\u{2026}\$2", $text) ?? $text;
    }

    /**
     * A pattern for the part of the template as written plainly or URL-encoded,
     * character by character.
     */
    private function written(string $part): string
    {
        $pattern = '';
        foreach (mb_str_split($part) as $character) {
            $quoted = preg_quote($character, '/');
            $pattern .= 1 === preg_match('/^[A-Za-z0-9._~-]$/', $character) ? $quoted : '(?:'.$quoted.'|'.preg_quote(rawurlencode($character), '/').')';
        }

        return $pattern;
    }

    /**
     * The token of an address made from the template, in lower case; null when
     * the address is not one of them. Case does not matter, as mail systems
     * often change it; surrounding blanks and angle brackets are ignored.
     */
    public function tokenIn(string $address): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }
        [$before, $after] = explode(self::PLACEHOLDER, $this->template, 2);
        $pattern = '/^'.preg_quote($before, '/').'(['.InboundToken::ALPHABET.']{'.InboundToken::LENGTH.'})'.preg_quote($after, '/').'$/iD';
        if (1 !== preg_match($pattern, trim($address, " \t\r\n<>"), $match)) {
            return null;
        }

        return strtolower($match[1]);
    }
}
