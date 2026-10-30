<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Registry;

use App\Party\Application\VatRegistry;
use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use App\Party\Domain\VatId;
use App\Shared\Infrastructure\Registry\PublicRegistryClient;
use App\Shared\Infrastructure\Registry\RegistryCache;
use Psr\Clock\ClockInterface;

/**
 * EU VIES over its REST interface: asks whether a VAT id is registered. The
 * trader name and address VIES returns are not used here.
 */
final readonly class ViesVatRegistry implements VatRegistry
{
    public const string URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    public function __construct(
        private PublicRegistryClient $client,
        private RegistryCache $cache,
        private ClockInterface $clock,
    ) {
    }

    public function checkVatId(VatId $vatId): RegistryCheck
    {
        $outcome = $this->cache->remember('vies', $vatId->toString(), function () use ($vatId): ?array {
            $answer = $this->client->requestJson('vies', 'POST', self::URL, [
                'json' => ['countryCode' => $vatId->country(), 'vatNumber' => substr($vatId->toString(), 2)],
            ]);
            $result = null === $answer || 200 !== $answer['status'] ? null : $this->result($answer['data']);

            return $result instanceof RegistryResult ? ['result' => $result->value, 'checkedAt' => $this->clock->now()->format(\DATE_ATOM)] : null;
        });

        $result = \is_string($outcome['result'] ?? null) ? RegistryResult::tryFrom($outcome['result']) : null;
        $checkedAt = \is_string($outcome['checkedAt'] ?? null) ? new \DateTimeImmutable($outcome['checkedAt']) : null;
        if (!$result instanceof RegistryResult || !$checkedAt instanceof \DateTimeImmutable) {
            return new RegistryCheck(RegistrySource::Vies, RegistryResult::Unavailable, $this->clock->now());
        }

        return new RegistryCheck(RegistrySource::Vies, $result, $checkedAt);
    }

    /**
     * @param array<mixed> $data
     */
    private function result(array $data): ?RegistryResult
    {
        // Error answers carry wrappers or a user error other than VALID/INVALID: the register could not decide.
        if ([] !== ($data['errorWrappers'] ?? [])) {
            return null;
        }
        $userError = $data['userError'] ?? null;
        if (\is_string($userError) && !\in_array($userError, ['VALID', 'INVALID'], true)) {
            return null;
        }
        $valid = $data['valid'] ?? null;

        return \is_bool($valid) ? ($valid ? RegistryResult::Valid : RegistryResult::Invalid) : null;
    }
}
