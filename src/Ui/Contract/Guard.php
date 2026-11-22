<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

/**
 * The checks a published value runs on what a module gives it, the same the
 * components run on their props: an icon in the locked icon set and a safe href.
 */
final class Guard
{
    public static function icon(?string $name): void
    {
        if (null === $name) {
            return;
        }
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) || !is_file(__DIR__.'/../assets/icons/lucide/'.$name.'.svg')) {
            throw new \InvalidArgumentException(sprintf('Icon "%s" is not in the locked icon set.', $name));
        }
    }

    /**
     * Only relative URLs and http, https, mailto and tel are allowed.
     */
    public static function href(?string $href): void
    {
        if (null === $href) {
            return;
        }
        if (str_contains($href, '\\') || 1 === preg_match('/[\x00-\x20]/', $href) || 1 === preg_match('#^[/\\\\]{2}#', $href)) {
            throw new \InvalidArgumentException('Unsafe href.');
        }
        if (1 !== preg_match('/^([a-z][a-z0-9+.\-]*):/i', $href, $m)) {
            return;
        }
        $scheme = strtolower($m[1]);
        $valid = match ($scheme) {
            'http', 'https' => 1 === preg_match('#^[a-z]+://[^/?\#]+#i', $href),
            'mailto', 'tel' => true,
            default => false,
        };
        if (!$valid) {
            throw new \InvalidArgumentException('Unsupported URL in href.');
        }
    }

    private function __construct()
    {
    }
}
