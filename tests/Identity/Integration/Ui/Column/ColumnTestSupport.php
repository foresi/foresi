<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Integration\Ui\Column;

use Foresi\Ui\Contract\Attribute;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the tests of a list's columns share: a translator that answers the key it is given, with
 * its parameters, and the columns as a plain list of what each declares.
 */
final class ColumnTestSupport
{
    public static function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            /**
             * @param array<mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return match ($id) {
                    'format.date' => 'Y-m-d',
                    'format.datetime' => 'Y-m-d H:i',
                    default => [] === $parameters ? $id : $id.'['.http_build_query($parameters, '', ',').']',
                };
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    /**
     * @param iterable<Attribute> $attributes
     *
     * @return list<array{string, string, string, bool, int, bool, bool, bool, list<string>}>
     */
    public static function describe(iterable $attributes): array
    {
        $described = [];
        foreach ($attributes as $attribute) {
            $described[] = [$attribute->key, $attribute->label, $attribute->type->value, $attribute->defaultVisible, $attribute->defaultPosition, $attribute->locked, $attribute->phone, $attribute->truncate, $attribute->aliases];
        }

        return $described;
    }

    /**
     * @param iterable<Attribute> $attributes
     */
    public static function find(iterable $attributes, string $key): Attribute
    {
        foreach ($attributes as $attribute) {
            if ($attribute->key === $key) {
                return $attribute;
            }
        }

        throw new \LogicException(\sprintf('No attribute "%s".', $key));
    }
}
