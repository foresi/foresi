<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\MessageTrust;

/**
 * Judges whether a message comes from its bank, from what the inbox's own
 * receiving server wrote about it. No signature is checked here.
 *
 * Anyone can write an Authentication-Results header into the mail they send, so
 * a pass is believed only from the topmost one: the receiving server adds its
 * own above every header the message arrived with, and it counts only when its
 * authserv-id is a trusted one. A fail is another matter: a claim of an aligned
 * DMARC or DKIM fail can only make the verdict stricter, so it counts from any
 * Authentication-Results header, trusted or not, and the message is Failed.
 *
 * The bank publishes a DMARC policy, so the receiving server always gives its
 * mail a DMARC result, and only that verifies: the header must hold exactly one
 * DMARC result, a pass whose From domain is the sender's or a parent of it and
 * is itself one of the bank's. A DKIM pass alone never verifies. The receiving
 * server copies text the sender chose, such as the HELO name or the envelope
 * sender, into the header without always quoting it, so a sender can make a
 * header seem to hold results the server never reached; a second DMARC result,
 * or a pass and a fail for one signing domain, shows that, and the message is
 * Failed. The receiving server removes or renames headers that carry its own id
 * (RFC 8601 §5), so a trusted id on any header below the topmost was planted or
 * misplaced, and the message is Failed too. A Failed message is rejected; only a
 * person who checked the payment in the bank records it anyway.
 *
 * An ARC pass rescues a signature broken by a forwarder only when the newest
 * ARC set was sealed by a trusted forwarder whose own results show an aligned
 * DKIM pass; it overrides the broken signature in the trusted topmost header,
 * never a DMARC fail and never a fail claimed by another header.
 */
final readonly class MailAuthentication
{
    public function __construct(
        private TrustSettings $settings,
        private AuthenticationResultsParser $parser,
    ) {
    }

    /**
     * @param list<string> $senderDomains the bank's own domains, from its parser; subdomains align
     */
    public function verdict(InboundMail $mail, array $senderDomains): TrustVerdict
    {
        $banks = array_map(TrustSettings::name(...), $senderDomains);
        $from = $this->soleSenderDomain($mail);
        if (null === $from || !$this->isBanks($from, $banks)) {
            return new TrustVerdict(MessageTrust::Failed, TrustVerdict::SENDER);
        }

        $all = array_map($this->parser->parse(...), $mail->headers('Authentication-Results'));
        $trusted = [] !== $all && $this->settings->trustsAuthserv($all[0]['authserv']);
        $rescued = $trusted && $this->claims($all[0]['results'], 'arc', 'pass') && $this->arcVouches($mail, $from, $banks);
        // A fail counts from every header, whoever wrote it: claiming one can only make the verdict stricter.
        if (array_any($all, fn (array $header): bool => $this->aligned($header['results'], 'dmarc', 'fail', 'header.from', $from, $banks))) {
            return new TrustVerdict(MessageTrust::Failed, TrustVerdict::DMARC_FAIL);
        }
        foreach ($all as $index => $header) {
            // The ARC rescue overrides a broken signature in the trusted header alone.
            if ((0 !== $index || !$rescued) && $this->aligned($header['results'], 'dkim', 'fail', 'header.d', $from, $banks)) {
                return new TrustVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL);
            }
        }
        if (!$trusted) {
            return new TrustVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS);
        }

        $results = $all[0]['results'];
        $contested = $this->contestedSigners($results);
        if (array_any($contested, fn (string $signer): bool => $this->isWithin($from, $signer) && $this->isBanks($signer, $banks))) {
            return new TrustVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL);
        }
        $dmarc = array_values(array_filter($results, static fn (array $result): bool => 'dmarc' === $result['method']));
        if (\count($dmarc) > 1 || [] !== $contested) {
            return new TrustVerdict(MessageTrust::Failed, TrustVerdict::AMBIGUOUS);
        }
        if (array_any(\array_slice($all, 1), fn (array $lower): bool => $this->settings->trustsAuthserv($lower['authserv']))) {
            return new TrustVerdict(MessageTrust::Failed, TrustVerdict::PLANTED);
        }
        if (!$rescued && !$this->aligned($dmarc, 'dmarc', 'pass', 'header.from', $from, $banks)) {
            return new TrustVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED);
        }

        return new TrustVerdict(MessageTrust::Verified, $rescued ? TrustVerdict::ARC : TrustVerdict::DMARC);
    }

    /**
     * The signing domains the header gives both a pass and a fail, which one
     * honest check never does.
     *
     * @param list<array{method: string, result: string, props: array<string, string>}> $results
     *
     * @return list<string>
     */
    private function contestedSigners(array $results): array
    {
        $outcomes = [];
        foreach ($results as $result) {
            if (\array_key_exists('header.d', $result['props']) && \in_array($result['result'], ['pass', 'fail'], true)) {
                $outcomes[TrustSettings::name($result['props']['header.d'])][$result['result']] = true;
            }
        }

        return array_keys(array_filter($outcomes, static fn (array $seen): bool => 2 === \count($seen)));
    }

    /**
     * The sender's domain when the message names exactly one sender: a second
     * From header, or a second address in it, could show the reader one sender
     * while the signature covers another. An address without a domain gives an
     * empty one, which is no bank's.
     */
    private function soleSenderDomain(InboundMail $mail): ?string
    {
        $headers = $mail->headers('From');
        if (1 !== \count($headers) || 1 !== substr_count($this->parser->bare($headers[0]), '@')) {
            return null;
        }

        return TrustSettings::name($mail->fromDomain() ?? '');
    }

    /**
     * @param list<string> $banks
     */
    private function isBanks(string $domain, array $banks): bool
    {
        return array_any($banks, fn (string $bank): bool => $this->isWithin($domain, $bank));
    }

    /**
     * Relaxed alignment: the domain is the parent itself or below it. Both are
     * names as TrustSettings::name() gives them, never ending in a dot, so an
     * empty parent contains no domain.
     */
    private function isWithin(string $domain, string $parent): bool
    {
        return $domain === $parent || str_ends_with($domain, '.'.$parent);
    }

    /**
     * Whether a result of the method has the outcome for a domain the sender's
     * domain aligns with and which is itself one of the bank's.
     *
     * @param list<array{method: string, result: string, props: array<string, string>}> $results
     * @param list<string>                                                              $banks
     */
    private function aligned(array $results, string $method, string $outcome, string $property, string $from, array $banks): bool
    {
        foreach ($results as $result) {
            if ($method !== $result['method'] || $outcome !== $result['result']) {
                continue;
            }
            $domain = TrustSettings::name($result['props'][$property] ?? '');
            if ($this->isWithin($from, $domain) && $this->isBanks($domain, $banks)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{method: string, result: string, props: array<string, string>}> $results
     */
    private function claims(array $results, string $method, string $outcome): bool
    {
        return array_any($results, fn (array $result): bool => $method === $result['method'] && $outcome === $result['result']);
    }

    /**
     * Whether the newest ARC set vouches for an aligned DKIM pass. The receiving
     * server's arc=pass says the seals hold; the sealer is the seal's signing
     * domain, which its key binds, so the results of that set count only when
     * that domain is trusted and wrote them. Anything unclear, such as two sets
     * of one instance or a set without its seal, vouches for nothing.
     *
     * @param list<string> $banks
     */
    private function arcVouches(InboundMail $mail, string $from, array $banks): bool
    {
        $results = [];
        foreach ($mail->headers('ARC-Authentication-Results') as $header) {
            if (1 !== preg_match('/\A\s*i\s*=\s*([0-9]+)\s*;(.*)\z/s', $header, $match)) {
                return false;
            }
            $results[(int) $match[1]][] = $match[2];
        }
        $seals = [];
        foreach ($mail->headers('ARC-Seal') as $header) {
            $tags = $this->parser->tags($header);
            $instance = $tags['i'] ?? '';
            if (1 !== preg_match('/\A[0-9]+\z/', $instance)) {
                return false;
            }
            $seals[(int) $instance][] = TrustSettings::name($tags['d'] ?? '');
        }
        if ([] === $results || [] === $seals) {
            return false;
        }

        $newest = max([...array_keys($results), ...array_keys($seals)]);
        if (1 !== \count($results[$newest] ?? []) || 1 !== \count($seals[$newest] ?? [])) {
            return false;
        }
        $sealer = $seals[$newest][0];
        $sealed = $this->parser->parse($results[$newest][0]);

        return $this->settings->trustsArcSealer($sealer)
            && $this->isWithin(TrustSettings::name($sealed['authserv']), $sealer)
            && $this->aligned($sealed['results'], 'dkim', 'pass', 'header.d', $from, $banks);
    }
}
