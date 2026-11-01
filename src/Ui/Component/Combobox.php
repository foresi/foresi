<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A text field that offers matches from the server in a keyboard-accessible listbox
 * (WAI-ARIA combobox with list autocomplete). The value goes in a hidden field named
 * "name", what is typed in the field named "textName". The server answers "url" plus
 * "q" with {results: [{id, label, hint}]}; everything else in a result reaches the
 * page in the "combobox:pick" event. Without JavaScript the typed text is sent, for
 * the server to resolve.
 */
#[AsTwigComponent]
final class Combobox extends AbstractControl
{
    public string $value = '';
    public string $text = '';
    public string $textName = '';
    public string $url = '';
    public ?string $placeholder = null;
    public string $emptyText = '';
    public int $minLength = 3;
}
