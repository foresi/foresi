<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Select extends AbstractControl
{
    /**
     * @var array<int|string, string|array<int|string, string>> value => already translated label,
     *                                                          or group label => such options
     */
    public array $options = [];
    public string $value = '';
}
