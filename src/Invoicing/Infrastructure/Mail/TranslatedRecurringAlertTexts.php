<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\DocumentLinks;
use App\Invoicing\Application\RecurringAlertTexts;
use App\Party\Domain\DocumentLanguage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The texts under recurring.alert in the translations, in the document's
 * language whatever the request's, and a link to the failing recurring invoices
 * on the configured base, DEFAULT_URI, which a run in the worker has too.
 */
final readonly class TranslatedRecurringAlertTexts implements RecurringAlertTexts
{
    private const string PREFIX = 'recurring.alert.';

    public function __construct(private TranslatorInterface $translator, private DocumentLinks $links)
    {
    }

    public function compose(string $firm, DocumentLanguage $language, array $failures, \DateTimeImmutable $today): array
    {
        $locale = $language->value;
        $date = DocumentLanguage::Czech === $language ? $today->format('j. n. Y') : $today->format('Y-m-d');
        $link = $this->trans('link', ['url' => $this->links->page('app_recurring', ['tab' => 'failing'])], $locale);
        if (1 === \count($failures)) {
            $stage = $this->stage($failures[0]['code']);

            return [
                'subject' => $this->trans('subject.'.$stage, [], $locale),
                'text' => implode("\n\n", [
                    $this->trans('opening.'.$stage, ['firm' => $firm, 'customer' => $failures[0]['customer'], 'date' => $date], $locale),
                    $this->reason($failures[0]['code'], $locale),
                    $this->trans('closing.'.$stage, [], $locale),
                    $link,
                ]),
            ];
        }
        $onlySends = [] === array_filter($failures, fn (array $failure): bool => 'send' !== $this->stage($failure['code']));
        $lines = array_map(fn (array $failure): string => '- '.$failure['customer'].': '.$this->reason($failure['code'], $locale), $failures);

        return [
            'subject' => $this->trans($onlySends ? 'digest.subject_send' : 'digest.subject', ['count' => \count($failures)], $locale),
            'text' => implode("\n\n", [
                $this->trans('digest.opening', ['firm' => $firm, 'date' => $date], $locale),
                implode("\n", $lines),
                $this->trans('digest.closing', [], $locale),
                $link,
            ]),
        ];
    }

    private function stage(string $code): string
    {
        return str_starts_with($code, 'send.') ? 'send' : 'issue';
    }

    private function reason(string $code, string $locale): string
    {
        $key = self::PREFIX.'reason.'.$code;
        $text = $this->translator->trans($key, [], null, $locale);

        return $text === $key ? $this->trans('reason.other', ['code' => $code], $locale) : $text;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans(self::PREFIX.$key, $parameters, null, $locale);
    }
}
