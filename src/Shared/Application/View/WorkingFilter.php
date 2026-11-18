<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * The filter a list shows when it differs from its view's, as the list's address
 * carries it (spec D10): `f=` holds the whole working filter, the D4 shape as
 * base64url JSON, so a link is shared and survives a reload as Linear's does. With
 * no `f=` the list shows its view's filter.
 */
final readonly class WorkingFilter
{
    public const string PARAM = 'f';

    public static function encode(ViewFilter $filter): string
    {
        return rtrim(strtr(base64_encode(json_encode($filter->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION)), '+/', '-_'), '=');
    }

    /**
     * The working filter of an address, or null when it carries none the list accepts:
     * a link to a field gone with a module shows the view as it is saved.
     */
    public static function decode(string $param, ListDefinition $list): ?ViewFilter
    {
        $json = base64_decode(strtr($param, '-_', '+/'), true);
        if (false === $json) {
            return null;
        }
        try {
            return ViewFilter::parse(json_decode($json, true, flags: \JSON_THROW_ON_ERROR), $list);
        } catch (\JsonException|InvalidFilter) {
            return null;
        }
    }

    /**
     * The list's address value for a working filter: none when it is the view's own.
     */
    public static function param(ViewFilter $working, ViewFilter $saved): ?string
    {
        return $working->toArray() === $saved->toArray() ? null : self::encode($working);
    }
}
