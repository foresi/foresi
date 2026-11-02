<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\InvalidMailServer;
use App\Invoicing\Domain\MailEncryption;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FirmMailServerTest extends TestCase
{
    public function testDefinesAServer(): void
    {
        $firm = $this->firm();
        $server = FirmMailServer::define($firm, ' smtp.example.com ', 587, MailEncryption::Starttls, 'invoices@example.com', 'k1:sealed', 'invoices@example.com', ' Example Firm ');

        self::assertSame($firm, $server->firm());
        self::assertSame($firm, $server->auditRoot());
        self::assertSame('smtp.example.com', $server->host());
        self::assertSame(587, $server->port());
        self::assertSame(MailEncryption::Starttls, $server->encryption());
        self::assertSame('invoices@example.com', $server->username());
        self::assertSame('k1:sealed', $server->sealedSecret());
        self::assertSame('invoices@example.com', $server->fromAddress());
        self::assertSame('Example Firm', $server->fromName());
        self::assertNull($server->verifiedAt());
        self::assertSame(36, \strlen($server->id()));
    }

    public function testAnIpv6LiteralIsStoredInBrackets(): void
    {
        foreach (['2606:2800:21F:CB07::1', '[2606:2800:21f:cb07::1]'] as $typed) {
            $server = FirmMailServer::define($this->firm(), $typed, 587, MailEncryption::Starttls, 'user', 'k1:sealed', 'invoices@example.com', 'Example Firm');
            self::assertSame('[2606:2800:21f:cb07::1]', $server->host());
        }
        self::assertSame('93.184.215.14', FirmMailServer::define($this->firm(), '93.184.215.14', 587, MailEncryption::Starttls, 'user', 'k1:sealed', 'invoices@example.com', 'Example Firm')->host());
    }

    public function testAChangeWithoutASecretKeepsTheOldOneAndNeedsAFreshCheck(): void
    {
        $server = $this->server();
        $server->markVerified(new \DateTimeImmutable('2026-11-02 10:00:00'));
        self::assertEquals(new \DateTimeImmutable('2026-11-02 10:00:00'), $server->verifiedAt());

        $server->change('mail.example.com', 465, MailEncryption::Tls, 'billing@example.com', null, 'billing@example.com', 'Billing');

        self::assertSame('k1:sealed', $server->sealedSecret());
        self::assertSame('mail.example.com', $server->host());
        self::assertSame(465, $server->port());
        self::assertSame(MailEncryption::Tls, $server->encryption());
        self::assertSame('billing@example.com', $server->username());
        self::assertSame('billing@example.com', $server->fromAddress());
        self::assertSame('Billing', $server->fromName());
        self::assertNull($server->verifiedAt());

        $server->change('mail.example.com', 2525, MailEncryption::Tls, 'billing@example.com', 'k2:other', 'billing@example.com', 'Billing');
        self::assertSame('k2:other', $server->sealedSecret());
        self::assertSame(2525, $server->port());
    }

    #[DataProvider('ports')]
    public function testPortOutsideTheListIsRefused(int $port): void
    {
        $this->expectException(InvalidMailServer::class);

        FirmMailServer::define($this->firm(), 'smtp.example.com', $port, MailEncryption::Starttls, 'user', 'k1:sealed', 'invoices@example.com', 'Example Firm');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function ports(): iterable
    {
        yield 'plain SMTP' => [25];
        yield 'zero' => [0];
        yield 'submission plus one' => [588];
    }

    public function testAChangeChecksAsMuchAsADefinition(): void
    {
        $this->expectException(InvalidMailServer::class);

        $this->server()->change('smtp.example.com', 25, MailEncryption::Starttls, 'user', null, 'invoices@example.com', 'Example Firm');
    }

    /**
     * @param array{host?: string, username?: string, fromAddress?: string, fromName?: string, secret?: string} $values
     */
    #[DataProvider('brokenValues')]
    public function testAHeaderBreakInFromNameIsRefused(array $values): void
    {
        $this->expectException(InvalidMailServer::class);

        FirmMailServer::define(
            $this->firm(),
            $values['host'] ?? 'smtp.example.com',
            587,
            MailEncryption::Starttls,
            $values['username'] ?? 'user',
            $values['secret'] ?? 'k1:sealed',
            $values['fromAddress'] ?? 'invoices@example.com',
            $values['fromName'] ?? 'Example Firm',
        );
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function brokenValues(): iterable
    {
        yield 'line break in the from name' => [['fromName' => "Example\r\nBcc: other@example.com"]];
        yield 'line feed in the from name' => [['fromName' => "Example\nBcc: other@example.com"]];
        yield 'line break in the from address' => [['fromAddress' => "invoices@example.com\r\nBcc: other@example.com"]];
        yield 'from address without a domain' => [['fromAddress' => 'invoices']];
        yield 'line break in the username' => [['username' => "user\r\nAUTH"]];
        yield 'empty host' => [['host' => ' ']];
        yield 'host with a space' => [['host' => 'smtp example.com']];
        yield 'host with a path' => [['host' => 'smtp.example.com/x']];
        yield 'host with a port' => [['host' => 'smtp.example.com:25']];
        yield 'IPv4 in brackets' => [['host' => '[93.184.215.14]']];
        yield 'empty secret' => [['secret' => '']];
    }

    private function server(): FirmMailServer
    {
        return FirmMailServer::define($this->firm(), 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', 'k1:sealed', 'invoices@example.com', 'Example Firm');
    }

    private function firm(): LegalEntity
    {
        return new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
    }

    public function testOnlyASettingsProblemTakesTheVerificationAway(): void
    {
        $server = $this->server();
        $server->markVerified(new \DateTimeImmutable('2026-11-02 10:00'));

        $server->markTestFailed(new \DateTimeImmutable('2026-11-02 11:00'), 'SMTP 550: the mail server refused the message.', false);
        self::assertSame('2026-11-02 10:00', $server->verifiedAt()?->format('Y-m-d H:i'));
        self::assertSame('SMTP 550: the mail server refused the message.', $server->lastTestError());

        $server->markTestFailed(new \DateTimeImmutable('2026-11-02 12:00'), 'SMTP 535: the mail server refused the login.', true);
        self::assertNull($server->verifiedAt());
        self::assertSame('2026-11-02 12:00', $server->lastTestAt()?->format('Y-m-d H:i'));

        $server->markVerified(new \DateTimeImmutable('2026-11-02 13:00'));
        self::assertNull($server->lastTestError());
    }
}
