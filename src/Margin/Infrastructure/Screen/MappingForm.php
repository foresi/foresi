<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Screen;

use Foresi\Margin\Application\MappingView;
use Foresi\Margin\Application\SetLineAllocationInput;
use Foresi\Margin\Application\TermView;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The form of a mapping, of a line or of a planned item: the kind and a few rows of recipients, each with a percent or an amount. It reads
 * what a person typed (the amounts in the language of the screen) into the input the use case takes, and what a
 * stored mapping says into the rows to show.
 *
 * @phpstan-type Row array{type: string, contactId: string, query: string, percent: string, amount: string, rest: bool}
 * @phpstan-type Values array{kind: string, rows: list<Row>}
 */
final readonly class MappingForm
{
    public const int ROWS = 4;
    private const int MAX_ROWS = 10;
    private const string AMOUNT = '/^\d{1,13}(?:\.\d{1,2})?$/D';

    public function __construct(private LocaleSwitcher $locales)
    {
    }

    /**
     * @return Values
     */
    public function fromMapping(?MappingView $mapping): array
    {
        $rows = array_map(fn (TermView $term): array => [
            'type' => 'own' === $term->recipient ? 'own' : 'client',
            'contactId' => 'own' === $term->recipient ? '' : $term->recipient,
            'query' => $term->recipientName ?? '',
            'percent' => null === $term->percent ? '' : $this->decimal($term->percent),
            'amount' => $this->field($term->amountCzk),
            'rest' => $term->rest,
        ], $mapping->terms ?? []);

        return ['kind' => $mapping->kind ?? 'whole', 'rows' => $this->padded($rows)];
    }

    /**
     * @param InputBag<bool|float|int|string> $post
     *
     * @return Values
     */
    public function fromRequest(InputBag $post): array
    {
        $rows = [];
        foreach (\array_slice($post->all('shares'), 0, self::MAX_ROWS) as $row) {
            $row = \is_array($row) ? $row : [];
            $rows[] = [
                'type' => $this->string($row, 'type'),
                'contactId' => $this->string($row, 'contactId'),
                'query' => $this->string($row, 'query'),
                'percent' => $this->string($row, 'percent'),
                'amount' => $this->string($row, 'amount'),
                'rest' => '' !== $this->string($row, 'rest'),
            ];
        }

        return ['kind' => $post->getString('kind'), 'rows' => $this->padded($rows)];
    }

    /**
     * @param Values $values
     *
     * @return SetLineAllocationInput|string the input, or the key of the translation that says what is missing
     */
    public function input(string $documentId, string $lineKey, array $values): SetLineAllocationInput|string
    {
        $shares = $this->shares($values);

        return \is_string($shares) ? $shares : new SetLineAllocationInput($documentId, $lineKey, $values['kind'], $shares);
    }

    /**
     * The shares the rows say, as the use cases take them: recipient and, by the kind, a percent, an amount or the rest.
     * Blank rows are left out.
     *
     * @param Values $values
     *
     * @return list<array<string, mixed>>|string the shares, or the key of the translation that says what is missing
     */
    public function shares(array $values): array|string
    {
        $shares = [];
        foreach ($values['rows'] as $row) {
            if ('' === $row['type']) {
                if ('' !== trim($row['percent'].$row['amount']) || '' !== $row['contactId']) {
                    return 'margin.screen.error.recipient';
                }
                continue;
            }
            $recipient = 'own' === $row['type'] ? 'own' : $row['contactId'];
            if ('' === $recipient) {
                return 'margin.screen.error.client';
            }
            $share = ['recipient' => $recipient];
            if ('percent' === $values['kind']) {
                $share['percent'] = '' === trim($row['percent']) ? null : TypedNumber::decimal(trim($row['percent']), $this->locales->getLocale());
            } elseif ('fixed' === $values['kind']) {
                if ($row['rest']) {
                    $share['rest'] = true;
                } else {
                    $amount = $this->typedAmount($row['amount']);
                    if (false === $amount) {
                        return 'margin.screen.error.amount';
                    }
                    $share['amount'] = $amount instanceof Money ? \sprintf('%d.%02d', intdiv($amount->minor(), 100), abs($amount->minor()) % 100) : null;
                }
            }
            $shares[] = $share;
        }

        return $shares;
    }

    private function typedAmount(string $typed): Money|false|null
    {
        if ('' === trim($typed)) {
            return null;
        }
        $decimal = TypedNumber::decimal($typed, $this->locales->getLocale());

        return 1 === preg_match(self::AMOUNT, $decimal) ? Money::of($decimal, Currency::CZK) : false;
    }

    private function decimal(string $decimal): string
    {
        return str_replace('.', $this->separator(), $decimal);
    }

    private function field(?Money $money): string
    {
        return $money instanceof Money ? \sprintf('%d%s%02d', intdiv(abs($money->minor()), 100), $this->separator(), abs($money->minor()) % 100) : '';
    }

    private function separator(): string
    {
        return new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
    }

    /**
     * @param list<Row> $rows
     *
     * @return list<Row>
     */
    private function padded(array $rows): array
    {
        while (\count($rows) < self::ROWS) {
            $rows[] = ['type' => '', 'contactId' => '', 'query' => '', 'percent' => '', 'amount' => '', 'rest' => false];
        }

        return $rows;
    }

    /**
     * @param array<mixed> $row
     */
    private function string(array $row, string $key): string
    {
        return \is_string($row[$key] ?? null) ? $row[$key] : '';
    }
}
