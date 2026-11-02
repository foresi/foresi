<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\MailText;
use App\Invoicing\Application\MailVariables;
use App\Party\Domain\DocumentLanguage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tokens of a mail template as the editor shows them: a readable label in the
 * template's own language, such as {Číslo dokladu} in a Czech template and
 * {Document number} in an English one. What is stored stays the stable name,
 * {number}, so a renamed label never breaks a saved text. Reading back accepts a
 * label in any case, spacing and Unicode form, with or without diacritics
 * ({cislo dokladu}), and the stable name too.
 */
final readonly class MailTokenLabels
{
    /** How much of an unknown token a message repeats. */
    private const int SHOWN = 40;

    public function __construct(private MailVariables $variables, private TranslatorInterface $translator)
    {
    }

    /**
     * @return array<string, string> stable name => label, in the order of the chips
     */
    public function labels(DocumentLanguage $language): array
    {
        $labels = [];
        foreach ($this->variables->names() as $name) {
            $labels[$name] = $this->translator->trans('firm.mail.variable.'.$name, locale: $language->value);
        }

        return $labels;
    }

    public function token(string $name, DocumentLanguage $language): string
    {
        return '{'.($this->labels($language)[$name] ?? $name).'}';
    }

    /**
     * Stored text to the editor: {number} becomes {Číslo dokladu}.
     */
    public function toEditor(string $text, DocumentLanguage $language): string
    {
        $labels = $this->labels($language);

        return (string) preg_replace_callback(MailText::TOKEN, static fn (array $token): string => isset($labels[$token[1]]) ? '{'.$labels[$token[1]].'}' : $token[0], $text);
    }

    /**
     * Editor text to the stored form. A token that is neither a label nor a name
     * stays as typed and is listed, as typed, among the unknown ones.
     *
     * @return array{text: string, unknown: list<string>}
     */
    public function fromEditor(string $text, DocumentLanguage $language): array
    {
        $names = [];
        foreach ($this->labels($language) as $name => $label) {
            $names[$this->key($label)] = $name;
            $names[$this->key($name)] ??= $name;
        }
        $unknown = [];
        $stored = (string) preg_replace_callback('/\{([^{}\r\n]+)\}/u', function (array $token) use ($names, &$unknown): string {
            $name = $names[$this->key($token[1])] ?? null;
            if (null === $name) {
                $unknown[] = '{'.(mb_strlen($token[1]) > self::SHOWN ? mb_substr($token[1], 0, self::SHOWN).'…' : $token[1]).'}';

                return $token[0];
            }

            return '{'.$name.'}';
        }, $text);

        return ['text' => $stored, 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * The form two spellings of one label share: NFC, no diacritics, lower case,
     * single spaces.
     */
    private function key(string $label): string
    {
        $normal = \Normalizer::normalize($label, \Normalizer::FORM_C);
        $folded = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()')?->transliterate(\is_string($normal) ? $normal : $label);

        return trim((string) preg_replace('/\s+/u', ' ', \is_string($folded) ? $folded : mb_strtolower($label)));
    }
}
