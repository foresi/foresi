<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\AuthenticationResultsParser;
use App\Banking\Application\InboundMail;
use App\Banking\Application\MailAuthentication;
use App\Banking\Application\TrustSettings;
use App\Banking\Application\TrustVerdict;
use App\Banking\Domain\MessageTrust;
use App\Banking\Infrastructure\Mime\ZbatesonMailReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures are read through the real mail reader, so the header order the
 * verdict relies on is the order of the bytes: the receiving server's own
 * Authentication-Results is the first line, and anything a sender wrote sits below it.
 */
final class MailAuthenticationTest extends TestCase
{
    private const array BANK = ['kb.cz', 'kbinfo.cz'];

    public function testAnAlignedDkimPassAloneIsNotEnough(): void
    {
        // The bank publishes DMARC, so its mail always carries a DMARC result; a signature alone proves less.
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'aligned-dkim-pass');
    }

    public function testDmarcPassIsVerified(): void
    {
        $this->assertVerdict(MessageTrust::Verified, TrustVerdict::DMARC, 'dmarc-pass');
    }

    public function testASubdomainSenderAligns(): void
    {
        $this->assertVerdict(MessageTrust::Verified, TrustVerdict::DMARC, 'kbinfo-sender');
        $this->assertVerdict(MessageTrust::Verified, TrustVerdict::DMARC, 'subdomain-sender');
    }

    public function testAnUnalignedDkimPassIsNotEnough(): void
    {
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'unaligned-dkim-pass');
    }

    public function testADkimPassForADifferentDomainIsNotEnough(): void
    {
        // A signature by the top-level domain aligns with any sender, so the signer must itself be the bank's.
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'dkim-pass-for-tld');
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'dkim-pass-for-lookalike');
        // The signer is narrower than the sender: the sender is not below it.
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'dkim-for-a-sub-signer');
    }

    public function testAnUnalignedDmarcPassIsNotEnough(): void
    {
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'unaligned-dmarc-pass');
    }

    public function testAlignedDkimFailIsFailed(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL, 'aligned-dkim-fail');
    }

    public function testDmarcFailIsFailed(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::DMARC_FAIL, 'dmarc-fail');
    }

    public function testAFailNextToAnAlignedPassIsFailed(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL, 'dkim-fail-and-pass');
    }

    public function testADmarcPassNextToAnAlignedDkimFailIsFailed(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL, 'dmarc-pass-with-dkim-fail');
    }

    public function testADmarcPassNextToADmarcFailIsFailed(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::DMARC_FAIL, 'dmarc-pass-and-fail');
    }

    public function testTwoDmarcResultsAreFailed(): void
    {
        // No honest check writes two: a sender smuggled one in, so the message goes to a person as rejected.
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::AMBIGUOUS, 'two-dmarc-results');
    }

    public function testAPassAndAFailForOneUnalignedSignerAreFailed(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::AMBIGUOUS, 'unaligned-dkim-pass-and-fail');
    }

    public function testADmarcFailCountsWithoutAnyTrustedServer(): void
    {
        // A claim of fail can only make the verdict stricter, so it counts whoever wrote it.
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::DMARC_FAIL, 'dmarc-fail', new TrustSettings([], []));
    }

    public function testAFailClaimedByAnUntrustedServerIsFailed(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'relay.example.net; dkim=fail header.d=kb.cz'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(new TrustVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL), $this->authentication()->verdict($mail, self::BANK));
    }

    public function testAFailClaimedBelowATrustedPassIsFailed(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'mx.example.com; dkim=pass header.d=kb.cz; dmarc=pass header.from=kb.cz'],
            ['Authentication-Results', 'relay.example.net; dmarc=fail header.from=kb.cz'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(new TrustVerdict(MessageTrust::Failed, TrustVerdict::DMARC_FAIL), $this->authentication()->verdict($mail, self::BANK));
    }

    public function testAnUnalignedFailClaimedByAnUntrustedServerIsOnlyUnverified(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'relay.example.net; dkim=fail header.d=example.net; dmarc=fail header.from=example.net'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(new TrustVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS), $this->authentication()->verdict($mail, self::BANK));
    }

    /**
     * Results a sender smuggled into the receiving server's own header: text the
     * server copies into it unescaped can close a comment, end a result early, or
     * a continuation line at the top of the message joins the header it adds.
     *
     * @return iterable<string, array{MessageTrust, string, string}>
     */
    public static function smuggledResults(): iterable
    {
        yield 'a folded first line joins the trusted header' => [MessageTrust::Failed, TrustVerdict::DMARC_FAIL, 'folded-first-line'];
        yield 'a parenthesis in the envelope sender comment' => [MessageTrust::Failed, TrustVerdict::DMARC_FAIL, 'paren-in-sender-comment'];
        yield 'an unquoted HELO with a semicolon' => [MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'semicolon-in-helo'];
    }

    #[DataProvider('smuggledResults')]
    public function testASmuggledPassDoesNotVerify(MessageTrust $trust, string $basis, string $fixture): void
    {
        $this->assertVerdict($trust, $basis, $fixture);
    }

    public function testAnUnalignedFailIsOnlyUnverified(): void
    {
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'unaligned-dkim-fail');
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'unaligned-dmarc-fail');
    }

    public function testASenderOutsideTheAllowlistIsFailed(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::SENDER, 'sender-outside-allowlist');
    }

    public function testAMailWithoutASenderIsFailed(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::SENDER, 'no-from');
    }

    public function testAParserWithoutSenderDomainsTrustsNoSender(): void
    {
        self::assertEquals(
            new TrustVerdict(MessageTrust::Failed, TrustVerdict::SENDER),
            $this->authentication()->verdict($this->read('aligned-dkim-pass'), []),
        );
    }

    public function testNoTrustedResultsIsUnverified(): void
    {
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS, 'aligned-dkim-pass', new TrustSettings([], []));
    }

    public function testTrustedNamesAreMatchedWithoutCaseOrRootDot(): void
    {
        $settings = new TrustSettings([' MX.Example.COM. '], ['ARC.example.com.']);

        $this->assertVerdict(MessageTrust::Verified, TrustVerdict::DMARC, 'dmarc-pass', $settings);
        $this->assertVerdict(MessageTrust::Verified, TrustVerdict::ARC, 'arc-trusted-rescue', $settings);
    }

    public function testAnEmptyTrustedNameTrustsNothing(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', '; dkim=pass header.d=kb.cz'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(
            new TrustVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS),
            $this->authentication(new TrustSettings(['', ' '], []))->verdict($mail, self::BANK),
        );
    }

    public function testASenderWithoutADomainIsFailed(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'mx.example.com; dkim=pass header.d=kb.cz'],
            ['From', 'info@'],
        ], 'info@');

        self::assertEquals(
            new TrustVerdict(MessageTrust::Failed, TrustVerdict::SENDER),
            $this->authentication()->verdict($mail, self::BANK),
        );
    }

    public function testAMissingResultsHeaderIsUnverified(): void
    {
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS, 'no-results');
    }

    public function testATrustedHeaderWithoutResultsIsUnsigned(): void
    {
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::UNSIGNED, 'unsigned');
    }

    public function testOnlyTheTopmostResultsHeaderCounts(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL, 'planted-below-fail');
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::PLANTED, 'planted-below-none');
    }

    public function testATrustedIdOnALowerResultsHeaderIsFailed(): void
    {
        // RFC 8601 §5: the receiving server removes or renames headers carrying its id, so one below its own was planted or misplaced.
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::PLANTED, 'planted-below-pass');
    }

    public function testAnUntrustedLowerResultsHeaderIsIgnored(): void
    {
        $this->assertVerdict(MessageTrust::Verified, TrustVerdict::DMARC, 'untrusted-below-pass');
    }

    public function testAnUntrustedAuthservIdIsIgnored(): void
    {
        // The topmost header is the relay's; the trusted-looking one below it may be anyone's.
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS, 'untrusted-authserv');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spoofedAuthservIds(): iterable
    {
        yield 'trusted id as a prefix' => ['spoofed-authserv-suffix'];
        yield 'trusted id as a suffix' => ['spoofed-authserv-prefix'];
        yield 'trusted id in a comment' => ['spoofed-authserv-comment'];
    }

    #[DataProvider('spoofedAuthservIds')]
    public function testASpoofedAuthservIdIsNotTrusted(string $fixture): void
    {
        $this->assertVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS, $fixture);
    }

    public function testArcPassFromATrustedSealerRescuesABrokenDkim(): void
    {
        $this->assertVerdict(MessageTrust::Verified, TrustVerdict::ARC, 'arc-trusted-rescue');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unrescuedArc(): iterable
    {
        yield 'untrusted sealer' => ['arc-untrusted-sealer'];
        yield 'trusted results under an untrusted seal' => ['arc-authserv-not-the-sealer'];
        yield 'trusted seal over untrusted results' => ['arc-sealer-not-the-authserv'];
        yield 'unaligned signature in the sealed results' => ['arc-unaligned-dkim'];
        yield 'two results of the highest instance' => ['arc-repeated-instance'];
        yield 'a higher seal without results' => ['arc-higher-seal-without-results'];
        yield 'results without a seal' => ['arc-results-without-seal'];
        yield 'the receiving server saw a broken chain' => ['arc-fail'];
        yield 'the receiving server checked no chain' => ['arc-not-claimed'];
    }

    #[DataProvider('unrescuedArc')]
    public function testArcFromAnUntrustedSealerDoesNotRescue(string $fixture): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL, $fixture);
    }

    public function testOnlyTheHighestArcInstanceCounts(): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL, 'arc-trusted-lower-instance');
    }

    public function testArcNeedsATrustedSealer(): void
    {
        $this->assertVerdict(
            MessageTrust::Failed,
            TrustVerdict::DKIM_FAIL,
            'arc-trusted-rescue',
            new TrustSettings(['mx.example.com'], []),
        );
    }

    public function testCommentsAndCaseInHeadersAreTolerated(): void
    {
        $this->assertVerdict(MessageTrust::Verified, TrustVerdict::DMARC, 'comments-and-case');
    }

    public function testTheSenderDomainsAreMatchedWithoutCase(): void
    {
        self::assertEquals(
            new TrustVerdict(MessageTrust::Verified, TrustVerdict::DMARC),
            $this->authentication()->verdict($this->read('dmarc-pass'), [' KB.CZ. ']),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function twoSenders(): iterable
    {
        yield 'two addresses in one header' => ['two-from-addresses'];
        yield 'two From headers' => ['two-from-headers'];
        yield 'a second From header with a space before its colon' => ['two-from-headers-spaced'];
        yield 'an address in the display name' => ['address-in-display-name'];
    }

    #[DataProvider('twoSenders')]
    public function testTwoFromAddressesAreFailed(string $fixture): void
    {
        $this->assertVerdict(MessageTrust::Failed, TrustVerdict::SENDER, $fixture);
    }

    public function testAQuotedLocalPartIsOneAddress(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'mx.example.com; dkim=pass header.d=kb.cz; dmarc=pass header.from=kb.cz'],
            ['From', 'Notices (KB, a@b) <"a@b"@kb.cz>'],
        ], '"a@b"@kb.cz');

        self::assertEquals(
            new TrustVerdict(MessageTrust::Verified, TrustVerdict::DMARC),
            $this->authentication()->verdict($mail, self::BANK),
        );
    }

    private function assertVerdict(MessageTrust $trust, string $basis, string $fixture, ?TrustSettings $settings = null): void
    {
        self::assertEquals(
            new TrustVerdict($trust, $basis),
            $this->authentication($settings)->verdict($this->read($fixture), self::BANK),
            $fixture,
        );
    }

    private function authentication(?TrustSettings $settings = null): MailAuthentication
    {
        return new MailAuthentication(
            $settings ?? new TrustSettings(['mx.example.com'], ['arc.example.com']),
            new AuthenticationResultsParser(),
        );
    }

    /**
     * @param list<array{string, string}> $headers
     */
    private function mail(array $headers, string $from): InboundMail
    {
        return new InboundMail(
            headers: $headers,
            from: $from,
            subject: 'Payment notice',
            messageId: null,
            date: null,
            text: null,
            html: null,
            attachmentNames: [],
            sha256: hash('sha256', ''),
            size: 0,
        );
    }

    private function read(string $fixture): InboundMail
    {
        $raw = file_get_contents(__DIR__.'/../../Fixtures/Banking/auth/'.$fixture.'.eml');
        self::assertIsString($raw);

        return new ZbatesonMailReader()->read($raw);
    }
}
