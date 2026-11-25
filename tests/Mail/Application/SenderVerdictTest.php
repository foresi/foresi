<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Foresi\Mail\Application\AuthenticationResultsParser;
use Foresi\Mail\Application\SenderVerdict;
use Foresi\Mail\Application\TrustSettings;
use Foresi\Mail\Application\Verdict;
use Foresi\Mail\Contract\InboundMail;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Mail\Infrastructure\Mime\ZbatesonMailReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures are read through the real mail reader, so the header order the
 * verdict relies on is the order of the bytes: the receiving server's own
 * Authentication-Results is the first line, and anything a sender wrote sits below it.
 */
final class SenderVerdictTest extends TestCase
{
    private const array BANK = ['kb.cz', 'kbinfo.cz'];

    public function testAnAlignedDkimPassAloneIsNotEnough(): void
    {
        // The bank publishes DMARC, so its mail always carries a DMARC result; a signature alone proves less.
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::UNSIGNED, 'aligned-dkim-pass');
    }

    public function testDmarcPassIsVerified(): void
    {
        $this->assertVerdict(Verdict::VERIFIED, Verdict::DMARC, 'dmarc-pass');
    }

    public function testASubdomainSenderAligns(): void
    {
        $this->assertVerdict(Verdict::VERIFIED, Verdict::DMARC, 'kbinfo-sender');
        $this->assertVerdict(Verdict::VERIFIED, Verdict::DMARC, 'subdomain-sender');
    }

    public function testAnUnalignedDkimPassIsNotEnough(): void
    {
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::UNSIGNED, 'unaligned-dkim-pass');
    }

    public function testADkimPassForADifferentDomainIsNotEnough(): void
    {
        // A signature by the top-level domain aligns with any sender, so the signer must itself be the bank's.
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::UNSIGNED, 'dkim-pass-for-tld');
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::UNSIGNED, 'dkim-pass-for-lookalike');
        // The signer is narrower than the sender: the sender is not below it.
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::UNSIGNED, 'dkim-for-a-sub-signer');
    }

    public function testAnUnalignedDmarcPassIsNotEnough(): void
    {
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::UNSIGNED, 'unaligned-dmarc-pass');
    }

    public function testAlignedDkimFailIsFailed(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::DKIM_FAIL, 'aligned-dkim-fail');
    }

    public function testDmarcFailIsFailed(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::DMARC_FAIL, 'dmarc-fail');
    }

    public function testAFailNextToAnAlignedPassIsFailed(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::DKIM_FAIL, 'dkim-fail-and-pass');
    }

    public function testADmarcPassNextToAnAlignedDkimFailIsFailed(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::DKIM_FAIL, 'dmarc-pass-with-dkim-fail');
    }

    public function testADmarcPassNextToADmarcFailIsFailed(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::DMARC_FAIL, 'dmarc-pass-and-fail');
    }

    public function testTwoDmarcResultsAreFailed(): void
    {
        // No honest check writes two: a sender smuggled one in, so the message goes to a person as rejected.
        $this->assertVerdict(Verdict::FAILED, Verdict::AMBIGUOUS, 'two-dmarc-results');
    }

    public function testAPassAndAFailForOneUnalignedSignerAreFailed(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::AMBIGUOUS, 'unaligned-dkim-pass-and-fail');
    }

    public function testADmarcFailCountsWithoutAnyTrustedServer(): void
    {
        // A claim of fail can only make the verdict stricter, so it counts whoever wrote it.
        $this->assertVerdict(Verdict::FAILED, Verdict::DMARC_FAIL, 'dmarc-fail', new TrustSettings([], []));
    }

    public function testAFailClaimedByAnUntrustedServerIsFailed(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'relay.example.net; dkim=fail header.d=kb.cz'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(new Verdict(Verdict::FAILED, Verdict::DKIM_FAIL), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    public function testAFailClaimedBelowATrustedPassIsFailed(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'mx.example.com; dkim=pass header.d=kb.cz; dmarc=pass header.from=kb.cz'],
            ['Authentication-Results', 'relay.example.net; dmarc=fail header.from=kb.cz'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(new Verdict(Verdict::FAILED, Verdict::DMARC_FAIL), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    public function testAnUnalignedFailClaimedByAnUntrustedServerIsOnlyUnverified(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'relay.example.net; dkim=fail header.d=example.net; dmarc=fail header.from=example.net'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(new Verdict(Verdict::UNKNOWN, Verdict::NO_RESULTS), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    /**
     * Results a sender smuggled into the receiving server's own header: text the
     * server copies into it unescaped can close a comment, end a result early, or
     * a continuation line at the top of the message joins the header it adds.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function smuggledResults(): iterable
    {
        yield 'a folded first line joins the trusted header' => [Verdict::FAILED, Verdict::DMARC_FAIL, 'folded-first-line'];
        yield 'a parenthesis in the envelope sender comment' => [Verdict::FAILED, Verdict::DMARC_FAIL, 'paren-in-sender-comment'];
        yield 'an unquoted HELO with a semicolon' => [Verdict::UNKNOWN, Verdict::UNSIGNED, 'semicolon-in-helo'];
    }

    #[DataProvider('smuggledResults')]
    public function testASmuggledPassDoesNotVerify(string $trust, string $basis, string $fixture): void
    {
        $this->assertVerdict($trust, $basis, $fixture);
    }

    public function testAnUnalignedFailIsOnlyUnverified(): void
    {
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::UNSIGNED, 'unaligned-dkim-fail');
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::UNSIGNED, 'unaligned-dmarc-fail');
    }

    public function testASenderOutsideTheAllowlistIsFailed(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::SENDER, 'sender-outside-allowlist');
    }

    public function testAMailWithoutASenderIsFailed(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::SENDER, 'no-from');
    }

    public function testAnEmptyAllowlistJudgesTheMailAgainstItsOwnFromDomain(): void
    {
        // An aligned DKIM pass alone is not enough here either, whatever the From domain is.
        self::assertEquals(
            new Verdict(Verdict::UNKNOWN, Verdict::UNSIGNED),
            $this->authentication()->judge($this->read('aligned-dkim-pass'), InboundChannel::Imap),
        );
    }

    public function testAnEmptyAllowlistVerifiesAnAlignedDmarcPassOfTheFromDomain(): void
    {
        self::assertEquals(
            new Verdict(Verdict::VERIFIED, Verdict::DMARC),
            $this->authentication()->judge($this->read('dmarc-pass'), InboundChannel::Imap),
        );
        self::assertEquals(
            new Verdict(Verdict::VERIFIED, Verdict::DMARC),
            $this->authentication()->judge($this->read('subdomain-sender'), InboundChannel::Imap),
        );
    }

    public function testAnEmptyAllowlistFailsAnAlignedDmarcFailOfTheFromDomain(): void
    {
        self::assertEquals(
            new Verdict(Verdict::FAILED, Verdict::DMARC_FAIL),
            $this->authentication()->judge($this->read('dmarc-fail'), InboundChannel::Imap),
        );
    }

    public function testAnEmptyAllowlistIsUnknownWithoutTrustedResults(): void
    {
        self::assertEquals(
            new Verdict(Verdict::UNKNOWN, Verdict::NO_RESULTS),
            $this->authentication()->judge($this->read('untrusted-authserv'), InboundChannel::Imap),
        );
        self::assertEquals(
            new Verdict(Verdict::UNKNOWN, Verdict::NO_RESULTS),
            $this->authentication()->judge($this->read('dmarc-pass'), InboundChannel::Webhook),
        );
    }

    public function testAnEmptyAllowlistStillFailsASenderWithoutADomain(): void
    {
        self::assertEquals(new Verdict(Verdict::FAILED, Verdict::SENDER), $this->authentication()->judge($this->read('no-from'), InboundChannel::Imap));
        self::assertEquals(
            new Verdict(Verdict::FAILED, Verdict::SENDER),
            $this->authentication()->judge($this->mail([['Authentication-Results', 'mx.example.com; dmarc=pass header.from=example.com'], ['From', 'info@']], 'info@'), InboundChannel::Imap),
        );
    }

    public function testNoTrustedResultsIsUnverified(): void
    {
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::NO_RESULTS, 'aligned-dkim-pass', new TrustSettings([], []));
    }

    /**
     * A relay that posts to the webhook has receiving servers of its own: the
     * mailbox's are never trusted for it, and the relay's never for the mailbox.
     */
    public function testEachChannelTrustsItsOwnReceivingServers(): void
    {
        $mailbox = new TrustSettings(['mx.example.com'], [], ['relay.example.net']);
        $relayed = $this->mail([
            ['Authentication-Results', 'relay.example.net; dkim=pass header.d=kb.cz; dmarc=pass header.from=kb.cz'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(new Verdict(Verdict::VERIFIED, Verdict::DMARC), $this->authentication($mailbox)->judge($relayed, InboundChannel::Webhook, self::BANK));
        self::assertEquals(new Verdict(Verdict::UNKNOWN, Verdict::NO_RESULTS), $this->authentication($mailbox)->judge($relayed, InboundChannel::Imap, self::BANK));
        self::assertEquals(new Verdict(Verdict::UNKNOWN, Verdict::NO_RESULTS), $this->authentication($mailbox)->judge($this->read('dmarc-pass'), InboundChannel::Webhook, self::BANK));
    }

    public function testTheWebhookTrustsNothingByDefault(): void
    {
        // Not the mailbox's servers: a relay is another path, set up on purpose.
        self::assertEquals(
            new Verdict(Verdict::UNKNOWN, Verdict::NO_RESULTS),
            $this->authentication(new TrustSettings(['mx.example.com'], ['arc.example.com']))->judge($this->read('dmarc-pass'), InboundChannel::Webhook, self::BANK),
        );
    }

    public function testTrustedNamesAreMatchedWithoutCaseOrRootDot(): void
    {
        $settings = new TrustSettings([' MX.Example.COM. '], ['ARC.example.com.']);

        $this->assertVerdict(Verdict::VERIFIED, Verdict::DMARC, 'dmarc-pass', $settings);
        $this->assertVerdict(Verdict::VERIFIED, Verdict::ARC, 'arc-trusted-rescue', $settings);
    }

    public function testAnEmptyTrustedNameTrustsNothing(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', '; dkim=pass header.d=kb.cz'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(
            new Verdict(Verdict::UNKNOWN, Verdict::NO_RESULTS),
            $this->authentication(new TrustSettings(['', ' '], []))->judge($mail, InboundChannel::Imap, self::BANK),
        );
    }

    public function testASenderWithoutADomainIsFailed(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'mx.example.com; dkim=pass header.d=kb.cz'],
            ['From', 'info@'],
        ], 'info@');

        self::assertEquals(
            new Verdict(Verdict::FAILED, Verdict::SENDER),
            $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK),
        );
    }

    public function testAMissingResultsHeaderIsUnverified(): void
    {
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::NO_RESULTS, 'no-results');
    }

    public function testATrustedHeaderWithoutResultsIsUnsigned(): void
    {
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::UNSIGNED, 'unsigned');
    }

    public function testOnlyTheTopmostResultsHeaderCounts(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::DKIM_FAIL, 'planted-below-fail');
        $this->assertVerdict(Verdict::FAILED, Verdict::PLANTED, 'planted-below-none');
    }

    public function testATrustedIdOnALowerResultsHeaderIsFailed(): void
    {
        // RFC 8601 §5: the receiving server removes or renames headers carrying its id, so one below its own was planted or misplaced.
        $this->assertVerdict(Verdict::FAILED, Verdict::PLANTED, 'planted-below-pass');
    }

    public function testAnUntrustedLowerResultsHeaderIsIgnored(): void
    {
        $this->assertVerdict(Verdict::VERIFIED, Verdict::DMARC, 'untrusted-below-pass');
    }

    public function testAnUntrustedAuthservIdIsIgnored(): void
    {
        // The topmost header is the relay's; the trusted-looking one below it may be anyone's.
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::NO_RESULTS, 'untrusted-authserv');
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
        $this->assertVerdict(Verdict::UNKNOWN, Verdict::NO_RESULTS, $fixture);
    }

    public function testArcPassFromATrustedSealerRescuesABrokenDkim(): void
    {
        $this->assertVerdict(Verdict::VERIFIED, Verdict::ARC, 'arc-trusted-rescue');
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
        $this->assertVerdict(Verdict::FAILED, Verdict::DKIM_FAIL, $fixture);
    }

    public function testOnlyTheHighestArcInstanceCounts(): void
    {
        $this->assertVerdict(Verdict::FAILED, Verdict::DKIM_FAIL, 'arc-trusted-lower-instance');
    }

    public function testArcNeedsATrustedSealer(): void
    {
        $this->assertVerdict(
            Verdict::FAILED,
            Verdict::DKIM_FAIL,
            'arc-trusted-rescue',
            new TrustSettings(['mx.example.com'], []),
        );
    }

    public function testCommentsAndCaseInHeadersAreTolerated(): void
    {
        $this->assertVerdict(Verdict::VERIFIED, Verdict::DMARC, 'comments-and-case');
    }

    public function testTheSenderDomainsAreMatchedWithoutCase(): void
    {
        self::assertEquals(
            new Verdict(Verdict::VERIFIED, Verdict::DMARC),
            $this->authentication()->judge($this->read('dmarc-pass'), InboundChannel::Imap, [' KB.CZ. ']),
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
        $this->assertVerdict(Verdict::FAILED, Verdict::SENDER, $fixture);
    }

    public function testAQuotedLocalPartIsOneAddress(): void
    {
        $mail = $this->mail([
            ['Authentication-Results', 'mx.example.com; dkim=pass header.d=kb.cz; dmarc=pass header.from=kb.cz'],
            ['From', 'Notices (KB, a@b) <"a@b"@kb.cz>'],
        ], '"a@b"@kb.cz');

        self::assertEquals(
            new Verdict(Verdict::VERIFIED, Verdict::DMARC),
            $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK),
        );
    }

    public function testALookalikeSenderDomainIsNotTheBanks(): void
    {
        // "notkb.cz" ends with the bank's domain, but is not below it.
        $mail = $this->mail([
            ['Authentication-Results', 'mx.example.com; dkim=pass header.d=notkb.cz; dmarc=pass header.from=notkb.cz'],
            ['From', 'info@notkb.cz'],
        ], 'info@notkb.cz');

        self::assertEquals(new Verdict(Verdict::FAILED, Verdict::SENDER), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    public function testADmarcPassForAnotherOfTheBanksDomainsIsNotEnough(): void
    {
        // kbinfo.cz is the bank's, but the sender is not below it.
        $mail = $this->mail([
            ['Authentication-Results', 'mx.example.com; dmarc=pass header.from=kbinfo.cz'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(new Verdict(Verdict::UNKNOWN, Verdict::UNSIGNED), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    public function testAPassAndAFailForAnotherOfTheBanksDomainsAreAmbiguous(): void
    {
        // The signer is the bank's, but the sender is not below it: not a broken signature of this sender.
        $mail = $this->mail([
            ['Authentication-Results', 'mx.example.com; dkim=pass header.d=kbinfo.cz; dkim=fail header.d=kbinfo.cz; dmarc=pass header.from=kb.cz'],
            ['From', 'info@kb.cz'],
        ], 'info@kb.cz');

        self::assertEquals(new Verdict(Verdict::FAILED, Verdict::AMBIGUOUS), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    public function testAnArcRescueDoesNotHideAPassAndAFailOfTheSendersSigner(): void
    {
        $mail = $this->arcMail(
            'mx.example.com; dkim=fail header.d=kb.cz; dkim=pass header.d=kb.cz; arc=pass',
            [['ARC-Seal', 'i=1; a=rsa-sha256; cv=none; d=arc.example.com; s=arc1; b=ZmFrZQ=='], ['ARC-Authentication-Results', 'i=1; arc.example.com; dkim=pass header.d=kb.cz']],
        );

        self::assertEquals(new Verdict(Verdict::FAILED, Verdict::DKIM_FAIL), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    public function testAnArcPassWithoutAnyArcHeadersRescuesNothing(): void
    {
        $mail = $this->arcMail('mx.example.com; dkim=fail header.d=kb.cz; arc=pass', []);

        self::assertEquals(new Verdict(Verdict::FAILED, Verdict::DKIM_FAIL), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    /**
     * @return iterable<string, array{list<array{string, string}>}>
     */
    public static function readableArcSets(): iterable
    {
        yield 'results folded over two lines' => [[
            ['ARC-Seal', 'i=1; a=rsa-sha256; cv=none; d=arc.example.com; s=arc1; b=ZmFrZQ=='],
            ['ARC-Authentication-Results', "i=1; arc.example.com;\r\n dkim=pass header.d=kb.cz"],
        ]];
        yield 'results numbered with a leading zero' => [[
            ['ARC-Seal', 'i=1; a=rsa-sha256; cv=none; d=arc.example.com; s=arc1; b=ZmFrZQ=='],
            ['ARC-Authentication-Results', 'i=01; arc.example.com; dkim=pass header.d=kb.cz'],
        ]];
        yield 'a seal numbered with a leading zero' => [[
            ['ARC-Seal', 'i=01; a=rsa-sha256; cv=none; d=arc.example.com; s=arc1; b=ZmFrZQ=='],
            ['ARC-Authentication-Results', 'i=1; arc.example.com; dkim=pass header.d=kb.cz'],
        ]];
    }

    /**
     * @param list<array{string, string}> $arc
     */
    #[DataProvider('readableArcSets')]
    public function testAnArcSetIsReadHoweverItIsWritten(array $arc): void
    {
        $mail = $this->arcMail('mx.example.com; dkim=fail header.d=kb.cz; arc=pass', $arc);

        self::assertEquals(new Verdict(Verdict::VERIFIED, Verdict::ARC), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    /**
     * The newest instance is the highest of the results and the seals together,
     * whichever order they come in; a set missing either half vouches for nothing.
     *
     * @return iterable<string, array{list<array{string, string}>}>
     */
    public static function newestArcSetIncomplete(): iterable
    {
        yield 'newer results without a seal' => [[
            ['ARC-Seal', 'i=1; a=rsa-sha256; cv=none; d=arc.example.com; s=arc1; b=ZmFrZQ=='],
            ['ARC-Authentication-Results', 'i=1; arc.example.com; dkim=pass header.d=kb.cz'],
            ['ARC-Authentication-Results', 'i=2; arc.example.com; dkim=pass header.d=kb.cz'],
        ]];
        yield 'a newer seal without results' => [[
            ['ARC-Seal', 'i=1; a=rsa-sha256; cv=none; d=arc.example.com; s=arc1; b=ZmFrZQ=='],
            ['ARC-Seal', 'i=2; a=rsa-sha256; cv=pass; d=arc.example.com; s=arc1; b=ZmFrZQ=='],
            ['ARC-Authentication-Results', 'i=1; arc.example.com; dkim=pass header.d=kb.cz'],
        ]];
    }

    /**
     * @param list<array{string, string}> $arc
     */
    #[DataProvider('newestArcSetIncomplete')]
    public function testAnIncompleteNewestArcSetRescuesNothing(array $arc): void
    {
        $mail = $this->arcMail('mx.example.com; dkim=fail header.d=kb.cz; arc=pass', $arc);

        self::assertEquals(new Verdict(Verdict::FAILED, Verdict::DKIM_FAIL), $this->authentication()->judge($mail, InboundChannel::Imap, self::BANK));
    }

    /**
     * A mail from the bank whose receiving server wrote the results, with the ARC headers below them.
     *
     * @param list<array{string, string}> $arc
     */
    private function arcMail(string $results, array $arc): InboundMail
    {
        return $this->mail([['Authentication-Results', $results], ...$arc, ['From', 'info@kb.cz']], 'info@kb.cz');
    }

    private function assertVerdict(string $trust, string $basis, string $fixture, ?TrustSettings $settings = null): void
    {
        self::assertEquals(
            new Verdict($trust, $basis),
            $this->authentication($settings)->judge($this->read($fixture), InboundChannel::Imap, self::BANK),
            $fixture,
        );
    }

    private function authentication(?TrustSettings $settings = null): SenderVerdict
    {
        return new SenderVerdict(
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
        $raw = file_get_contents(__DIR__.'/../../Fixtures/Mail/auth/'.$fixture.'.eml');
        self::assertIsString($raw);

        return new ZbatesonMailReader()->read($raw);
    }
}
