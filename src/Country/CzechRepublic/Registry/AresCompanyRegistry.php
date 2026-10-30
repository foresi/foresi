<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Registry;

use App\Party\Application\CompanyRegistry;
use App\Party\Application\RegistryRecord;
use App\Party\Domain\Address;
use App\Party\Domain\InvalidRegistrationNumber;
use App\Party\Domain\InvalidVatId;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Shared\Infrastructure\Registry\PublicRegistryClient;
use App\Shared\Infrastructure\Registry\RegistryCache;

/**
 * ARES, the Czech aggregator of public registers (Ministry of Finance), over
 * its REST interface. Informational data only.
 */
final readonly class AresCompanyRegistry implements CompanyRegistry
{
    public const string BASE_URL = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/';
    private const int MAX_RESULTS = 50;

    public function __construct(private PublicRegistryClient $client, private RegistryCache $cache)
    {
    }

    public function lookupByRegistrationNumber(RegistrationNumber $number): ?RegistryRecord
    {
        if ('CZ' !== $number->countryCode || 1 !== preg_match('/^[0-9]{8}\z/', $number->normalised())) {
            return null;
        }
        $ico = $number->normalised();

        $subject = $this->cache->remember('ares', 'ico.'.$ico, function () use ($ico): ?array {
            $answer = $this->client->requestJson('ares', 'GET', self::BASE_URL.'ekonomicke-subjekty/'.$ico, [], [200, 404]);
            if (null === $answer) {
                return null;
            }

            // 404 is a definite "no such subject"; every other status was already logged as a failure.
            return 200 === $answer['status'] ? $answer['data'] : [];
        });

        if ([] === $subject || null === $subject) {
            return null;
        }

        $record = $this->record($subject);
        if (!$record instanceof RegistryRecord) {
            $this->client->fail('ares', 'subject answer is incomplete');
        }

        return $record;
    }

    public function searchByName(string $country, string $query, int $limit): array
    {
        $query = $this->normaliseQuery($query);
        if ('CZ' !== strtoupper($country) || '' === $query || $limit < 1) {
            return [];
        }
        $limit = min($limit, self::MAX_RESULTS);

        $found = $this->cache->remember('ares', 'name.'.$limit.'.'.mb_strtolower($query), function () use ($query, $limit): ?array {
            $answer = $this->client->requestJson('ares', 'POST', self::BASE_URL.'ekonomicke-subjekty/vyhledat', [
                'json' => ['obchodniJmeno' => $query, 'start' => 0, 'pocet' => $limit],
            ]);
            if (null === $answer) {
                return null;
            }
            $subjects = $answer['data']['ekonomickeSubjekty'] ?? [];

            return \is_array($subjects) ? array_values($subjects) : [];
        });

        $records = [];
        foreach ($found ?? [] as $subject) {
            $record = \is_array($subject) ? $this->record($subject) : null;
            if ($record instanceof RegistryRecord) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array<mixed> $subject
     */
    private function record(array $subject): ?RegistryRecord
    {
        $ico = $subject['ico'] ?? null;
        $name = $subject['obchodniJmeno'] ?? null;
        if (!\is_string($ico) || !\is_string($name) || '' === trim($name)) {
            return null;
        }

        try {
            $number = new RegistrationNumber('CZ', $ico);
        } catch (InvalidRegistrationNumber) {
            return null;
        }

        return new RegistryRecord($number, trim($name), $this->vatId($subject['dic'] ?? null), $this->seat($subject['sidlo'] ?? null));
    }

    private function vatId(mixed $dic): ?VatId
    {
        if (!\is_string($dic) || '' === trim($dic)) {
            return null;
        }

        try {
            return VatId::fromString($dic);
        } catch (InvalidVatId) {
            return null;
        }
    }

    private function seat(mixed $sidlo): ?Address
    {
        if (!\is_array($sidlo)) {
            return null;
        }

        $city = $this->text($sidlo['nazevObce'] ?? null);
        if ('' === $city) {
            return null;
        }

        // Places without streets are addressed by the part of the municipality and the house number.
        $street = $this->text($sidlo['nazevUlice'] ?? null);
        $part = $this->text($sidlo['nazevCastiObce'] ?? null);
        $place = '' !== $street ? $street : ('' !== $part ? $part : $city);
        $house = $this->text($sidlo['cisloDomovni'] ?? null);
        $orientation = $this->text($sidlo['cisloOrientacni'] ?? null).$this->text($sidlo['cisloOrientacniPismeno'] ?? null);
        $number = '' === $orientation ? $house : $house.'/'.$orientation;

        $postalCode = $this->text($sidlo['psc'] ?? null);
        if (1 === preg_match('/^[0-9]{5}\z/', $postalCode)) {
            $postalCode = substr($postalCode, 0, 3).' '.substr($postalCode, 3);
        }

        $country = $this->text($sidlo['kodStatu'] ?? null);

        return new Address(trim($place.' '.$number), $city, $postalCode, 1 === preg_match('/^[A-Z]{2}\z/', $country) ? $country : 'CZ');
    }

    /**
     * Whitespace collapsed and Unicode NFC, so "Příklad  s.r.o." typed two ways shares one cache entry.
     */
    private function normaliseQuery(string $query): string
    {
        $query = preg_replace('/\s+/u', ' ', $query) ?? $query;
        $normalised = \Normalizer::normalize($query, \Normalizer::FORM_C);

        return trim(\is_string($normalised) ? $normalised : $query);
    }

    private function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) ? trim((string) $value) : '';
    }
}
