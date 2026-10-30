<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Registry;

use App\Party\Application\ReliabilityResult;
use App\Party\Application\VatPayerReliability;
use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use App\Party\Domain\VatId;
use App\Shared\Infrastructure\Registry\PublicRegistryClient;
use App\Shared\Infrastructure\Registry\RegistryCache;
use Psr\Clock\ClockInterface;

/**
 * The register of unreliable VAT payers of the Czech Financial Administration
 * (ADIS, service rozhraniCRPDPH). The SOAP request is a plain XML string and the
 * answer is read with DOM, so ext-soap is not needed.
 */
final readonly class AdisVatPayerReliability implements VatPayerReliability
{
    public const string URL = 'https://adisrws.mfcr.cz/adistc/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP';
    public const int BATCH_SIZE = 100;
    private const string NAMESPACE = 'http://adis.mfcr.cz/rozhraniCRPDPH/';
    private const string ACTION = 'getStatusNespolehlivyPlatceRozsireny';

    public function __construct(
        private PublicRegistryClient $client,
        private RegistryCache $cache,
        private ClockInterface $clock,
    ) {
    }

    public function check(VatId $vatId): ReliabilityResult
    {
        return $this->checkMany([$vatId])[$vatId->toString()];
    }

    public function checkMany(array $vatIds): array
    {
        $results = [];
        $missing = [];
        foreach ($vatIds as $vatId) {
            $key = $vatId->toString();
            if (isset($results[$key]) || isset($missing[$key])) {
                continue;
            }
            // The register knows Czech VAT ids only.
            if ('CZ' !== $vatId->country()) {
                $results[$key] = $this->unavailable();
                continue;
            }

            $cached = $this->cache->get('adis', $key);
            if (null === $cached) {
                $missing[$key] = substr($key, 2);
            } else {
                $results[$key] = $this->fromCache($cached);
            }
        }

        foreach (array_chunk($missing, self::BATCH_SIZE, true) as $batch) {
            $answers = $this->ask(array_values($batch));
            foreach ($batch as $key => $dic) {
                $answer = $answers[$dic] ?? null;
                if (null === $answer) {
                    $results[$key] = $this->unavailable();
                    continue;
                }
                $cache = ['result' => $answer['result']->value, 'accounts' => $answer['accounts'], 'checkedAt' => $this->clock->now()->format(\DATE_ATOM)];
                $this->cache->put('adis', $key, $cache);
                $results[$key] = $this->fromCache($cache);
            }
        }

        return $results;
    }

    /**
     * @param list<string> $dics
     *
     * @return array<string, array{result: RegistryResult, accounts: list<string>}> keyed by DIC without the prefix; ids the register did not answer for are missing
     */
    private function ask(array $dics): array
    {
        $answer = $this->client->request('adis', 'POST', self::URL, [
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"'.self::NAMESPACE.self::ACTION.'"',
            ],
            'body' => $this->envelope($dics),
        ]);

        if (null === $answer || 200 !== $answer['status']) {
            return [];
        }

        return $this->parse($answer['body']);
    }

    /**
     * @param list<string> $dics
     */
    private function envelope(array $dics): string
    {
        $items = '';
        foreach ($dics as $dic) {
            $items .= '<roz:dic>'.htmlspecialchars($dic, \ENT_XML1 | \ENT_QUOTES).'</roz:dic>';
        }

        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:roz="'.self::NAMESPACE.'">'
            .'<soapenv:Body><roz:StatusNespolehlivyPlatceRozsirenyRequest>'.$items.'</roz:StatusNespolehlivyPlatceRozsirenyRequest></soapenv:Body>'
            .'</soapenv:Envelope>';
    }

    /**
     * @return array<string, array{result: RegistryResult, accounts: list<string>}>
     */
    private function parse(string $xml): array
    {
        // The answer is untrusted input: no DTDs, no network access.
        if ('' === trim($xml) || str_contains($xml, '<!DOCTYPE') || str_contains($xml, '<!ENTITY')) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING)) {
                return [];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('r', self::NAMESPACE);

        // Ids the register does not list (or lists as NENALEZEN) simply get no answer.
        $answers = [];
        $payers = $xpath->query('//r:statusPlatceDPH');
        foreach (false === $payers ? [] : $payers as $payer) {
            \assert($payer instanceof \DOMElement);
            $result = match ($payer->getAttribute('nespolehlivyPlatce')) {
                'ANO' => RegistryResult::Unreliable,
                'NE' => RegistryResult::Reliable,
                default => null, // NENALEZEN: not a VAT payer, so there is nothing to conclude
            };
            if (null === $result) {
                continue;
            }
            $answers[$payer->getAttribute('dic')] = ['result' => $result, 'accounts' => $this->accounts($xpath, $payer)];
        }

        return $answers;
    }

    /**
     * @return list<string>
     */
    private function accounts(\DOMXPath $xpath, \DOMElement $payer): array
    {
        $accounts = [];
        $nodes = $xpath->query('r:zverejneneUcty/r:ucet/*', $payer);
        foreach (false === $nodes ? [] : $nodes as $node) {
            \assert($node instanceof \DOMElement);
            if ('nestandardniUcet' === $node->localName) {
                $account = $node->getAttribute('cislo');
            } else {
                $prefix = ltrim($node->getAttribute('predcisli'), '0');
                $account = ('' === $prefix ? '' : $prefix.'-').$node->getAttribute('cislo').'/'.$node->getAttribute('kodBanky');
            }
            if ('' !== $account && '/' !== $account) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * @param array<mixed> $cached
     */
    private function fromCache(array $cached): ReliabilityResult
    {
        $accounts = array_values(array_filter(\is_array($cached['accounts'] ?? null) ? $cached['accounts'] : [], \is_string(...)));
        $result = RegistryResult::tryFrom(\is_string($cached['result'] ?? null) ? $cached['result'] : '') ?? RegistryResult::Unavailable;
        $at = \is_string($cached['checkedAt'] ?? null) ? new \DateTimeImmutable($cached['checkedAt']) : $this->clock->now();

        return new ReliabilityResult(new RegistryCheck(RegistrySource::UnreliablePayer, $result, $at), $accounts);
    }

    private function unavailable(): ReliabilityResult
    {
        return new ReliabilityResult(new RegistryCheck(RegistrySource::UnreliablePayer, RegistryResult::Unavailable, $this->clock->now()));
    }
}
