<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

/**
 * A variable source with image variables (VariableDefinition::$image) also says
 * where their files are. Only local files: a template never reaches the network.
 */
interface DocumentImageSource
{
    /**
     * @param string $variable the name of the image variable, such as "logo"
     * @param string $value    a value this source gave for it
     *
     * @return string|null the absolute path of a PNG or JPEG file; null when there is no such file
     */
    public function imageFile(string $variable, string $value): ?string;
}
