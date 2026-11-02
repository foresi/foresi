<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Audit\Application\AuditActor;
use App\Banking\Application\AcceptRefused;
use App\Banking\Application\AcceptRejectedMessage;
use App\Banking\Application\BankMessageParsers;
use App\Banking\Application\BankMovements;
use App\Banking\Application\GenerateAddress;
use App\Banking\Application\InboundMessages;
use App\Banking\Application\PendingChanges;
use App\Banking\Application\RetryInboundMessage;
use App\Banking\Application\UnknownInboundMessage;
use App\Banking\Domain\InboundStatus;
use App\Banking\Infrastructure\Navigation\BankNotices;
use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\Organisation;
use App\Identity\Application\StaffCheck;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Invoicing\Application\PaymentState;
use App\Party\Domain\Address;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Banking\Support\InboundMailKit;
use App\Tests\Banking\Support\InboundTestCase;
use App\Tests\Banking\Support\Scalars;
use App\Tests\Party\Support\FirmAccounts;
use App\Ui\Notice\ShellNotice;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The pipeline from a stored message to recorded movements, one step at a time:
 * whose account it is, what a parser could not read, what someone decided, and
 * that processing a message again never records it twice.
 */
final class ProcessMessageTest extends InboundTestCase
{
    private const string OTHER_IBAN = 'CZ5508000000001234567899';

    public function testAnAccountOfAnotherFirmIsRejectedAndCannotBeAccepted(): void
    {
        $invoice = $this->kbInvoice();
        $other = new LegalEntity('Other Firm s.r.o.', new RegistrationNumber('CZ', '87654320'), null, new Address('Other Street 3', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($other);
        $account = FirmAccounts::add($this->em, $other, self::OTHER_IBAN);
        $this->em->flush();
        $address = self::getContainer()->get(GenerateAddress::class)->generate($account->id());
        // Sent to the other firm's address, about this firm's account: someone else's money for that firm.
        $id = $this->received($this->notice('incoming-booked', to: $address));

        self::assertSame(InboundStatus::Rejected, $this->process->process($id));

        $row = $this->messageRow($id);
        self::assertSame(['rejected', 'verified', 'account_mismatch', 'cz.kb'], [$row['status'], $row['trust'], $row['failure'], $row['parser_id']]);
        self::assertSame([], $this->movementsOf($id));
        try {
            $this->accept()->accept($id);
            self::fail('An account of another firm is never recorded.');
        } catch (AcceptRefused $refused) {
            self::assertSame(AcceptRefused::ACCOUNT_MISMATCH, $refused->reason);
        }
        self::assertSame('rejected', $this->messageRow($id)['status']);
        self::assertSame([], $this->movementsOf($id));
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));
    }

    public function testAnotherAccountOfTheSameFirmIsAccepted(): void
    {
        // KB+ sends every notice to one address: the one of the firm's other account here.
        $invoice = $this->kbInvoice();
        $firm = $this->em->find(LegalEntity::class, $this->firm->id());
        self::assertInstanceOf(LegalEntity::class, $firm);
        $second = FirmAccounts::add($this->em, $firm, self::OTHER_IBAN);
        $this->em->flush();
        $address = self::getContainer()->get(GenerateAddress::class)->generate($second->id());
        $id = $this->received($this->notice('incoming-booked', to: $address));

        self::assertSame(InboundStatus::Processed, $this->process->process($id));

        $movements = $this->movementsOf($id);
        self::assertCount(1, $movements);
        // Booked on the account the bank named, not the one of the address.
        self::assertSame([$this->account->id(), 'paired'], [$movements[0]['firm_bank_account_id'], $movements[0]['state']]);
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testAnArchivedAccountIsRejected(): void
    {
        $id = $this->received($this->notice('incoming-booked'));
        $account = $this->em->find(FirmBankAccount::class, $this->account->id());
        self::assertInstanceOf(FirmBankAccount::class, $account);
        $account->archive(new \DateTimeImmutable('2026-11-10'));
        $this->em->flush();

        self::assertSame(InboundStatus::Rejected, $this->process->process($id));

        self::assertSame('account_mismatch', $this->messageRow($id)['failure']);
        self::assertSame([], $this->movementsOf($id));
    }

    public function testANotBookedNoticeIsIgnored(): void
    {
        $id = $this->received($this->notice('incoming-not-booked'));

        self::assertSame(InboundStatus::Ignored, $this->process->process($id));

        $row = $this->messageRow($id);
        self::assertSame(['ignored', null, 'status:Zamítnuto', 'cz.kb', 'verified'], [$row['status'], $row['failure'], $row['failure_detail'], $row['parser_id'], $row['trust']]);
        self::assertSame([], $this->movementsOf($id));
    }

    public function testAnUnknownLayoutFailsWithItsReasonAndKeepsTheRaw(): void
    {
        $raw = $this->notice('incoming-unknown-status');
        $id = $this->received($raw);

        self::assertSame(InboundStatus::Failed, $this->process->process($id));

        $row = $this->messageRow($id);
        self::assertSame(['failed', 'unknown_layout', 'cz.kb', 1, 1], [$row['status'], $row['failure'], $row['parser_id'], self::int($row['parser_version']), self::int($row['attempts'])]);
        self::assertStringContainsString('Odloženo', Scalars::text($row['failure_detail']));
        self::assertSame([], $this->movementsOf($id));
        $stored = $this->em->getConnection()->fetchAssociative('SELECT tenant_id, raw_key, raw_sha256 FROM inbound_message WHERE id = ?', [$id]);
        self::assertIsArray($stored);
        self::assertSame($raw, self::getContainer()->get(\App\Banking\Application\InboundFiles::class)->read(Scalars::text($stored['tenant_id']), Scalars::text($stored['raw_key']), Scalars::text($stored['raw_sha256'])));
    }

    public function testAMailNoParserKnowsIsNotBankMail(): void
    {
        // Such as the confirmation a mailbox sends before it forwards anything.
        $id = $this->received(InboundMailKit::mail($this->address));

        self::assertSame(InboundStatus::NotBankMail, $this->process->process($id));

        $row = $this->messageRow($id);
        self::assertSame(['notbankmail', self::getContainer()->get(BankMessageParsers::class)->signature(), null, null], [$row['status'], $row['parser_id'], $row['parser_version'], $row['trust']]);
        self::assertSame([], $this->movementsOf($id));
    }

    public function testRetryIsManualAndIdempotent(): void
    {
        $retry = new RetryInboundMessage($this->process);
        $failed = $this->received($this->notice('incoming-unknown-status'));
        $this->process->process($failed);

        // A failed message is read again on request.
        self::assertSame(InboundStatus::Failed, $retry->retry($failed));
        self::assertSame(2, self::int($this->messageRow($failed)['attempts']));

        $invoice = $this->kbInvoice();
        $paid = $this->received($this->notice('incoming-booked'));
        self::assertSame(InboundStatus::Processed, $retry->retry($paid));
        // Pressed again: a processed message is left as it is.
        self::assertSame(InboundStatus::Processed, $retry->retry($paid));

        self::assertCount(1, $this->movementsOf($paid));
        self::assertSame(1, self::int($this->messageRow($paid)['attempts']));
        self::assertSame(1, $this->paymentCount());
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testAcceptingARejectedNoticeRecordsItUnpaired(): void
    {
        $invoice = $this->kbInvoice();
        $id = $this->received($this->notice('incoming-booked', auth: 'aligned-dkim-fail'));
        self::assertSame(InboundStatus::Rejected, $this->process->process($id));
        $accept = $this->accept();

        self::assertSame(InboundStatus::Processed, $accept->accept($id));

        $row = $this->messageRow($id);
        self::assertSame(['processed', 'unverified', 'accepted-by-user', null], [$row['status'], $row['trust'], $row['trust_basis'], $row['failure']]);
        $movements = $this->movementsOf($id);
        self::assertCount(1, $movements);
        self::assertSame(['unmatched', 'unverified', 'unverified', 'accepted-by-user', null], [$movements[0]['state'], $movements[0]['unmatched_reason'], $movements[0]['trust'], $movements[0]['trust_basis'], $movements[0]['payment_id']]);
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));

        $this->expectExceptionObject(new AcceptRefused(AcceptRefused::NOT_REJECTED));
        $accept->accept($id);
    }

    public function testAMessageThatIsNotRejectedCannotBeAccepted(): void
    {
        $id = $this->received($this->notice('incoming-unknown-status'));
        $this->process->process($id);

        try {
            $this->accept()->accept($id);
            self::fail('Only a rejected message is recorded anyway.');
        } catch (AcceptRefused $refused) {
            self::assertSame(AcceptRefused::NOT_REJECTED, $refused->reason);
        }
        self::assertSame('failed', $this->messageRow($id)['status']);
    }

    public function testProcessingTheSameMessageTwiceMakesOneMovement(): void
    {
        $this->kbInvoice();
        $id = $this->received($this->notice('incoming-booked'));

        self::assertSame(InboundStatus::Processed, $this->process->process($id));
        self::assertSame(InboundStatus::Processed, $this->process->process($id));

        self::assertCount(1, $this->movementsOf($id));
        self::assertSame(1, $this->paymentCount());
        self::assertSame(1, self::int($this->messageRow($id)['attempts']));
    }

    public function testATamperedRawFileFails(): void
    {
        $this->kbInvoice();
        $id = $this->received($this->notice('incoming-booked'));
        $key = Scalars::text($this->em->getConnection()->fetchOne('SELECT raw_key FROM inbound_message WHERE id = ?', [$id]));
        $path = Scalars::text(self::getContainer()->getParameter('app.inbound_mail_dir')).'/'.$key;
        file_put_contents($path, str_replace('150,00 CZK', '950,00 CZK', (string) file_get_contents($path)));

        self::assertSame(InboundStatus::Failed, $this->process->process($id));

        $row = $this->messageRow($id);
        self::assertSame(['failed', 'raw_changed', null], [$row['status'], $row['failure'], $row['parser_id']]);
        self::assertSame([], $this->movementsOf($id));
        self::assertSame(0, $this->paymentCount());
    }

    public function testAMessageOfAnotherTenantIsUnknown(): void
    {
        $id = $this->received($this->notice('incoming-booked'));
        $context = self::getContainer()->get(TenantContext::class);
        $tenant = $context->current();
        $context->switchTo(TenantId::generate());
        try {
            $this->process->process($id);
            self::fail('A message of another tenant is not processed.');
        } catch (UnknownInboundMessage) {
        } finally {
            $context->switchTo($tenant);
        }
        self::assertSame(['received', 0], [$this->messageRow($id)['status'], self::int($this->messageRow($id)['attempts'])]);
    }

    public function testAnUnexpectedErrorUndoesTheRunAndCountsTheAttempt(): void
    {
        $this->kbInvoice();
        $id = $this->received($this->notice('incoming-booked'));
        $broken = new class implements PendingChanges {
            public function store(): void
            {
                throw new \RuntimeException('The database went away.');
            }
        };

        try {
            $this->processWith(pending: $broken)->process($id);
            self::fail('The error reaches the queue, which tries again.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The database went away.', $failure->getMessage());
        }

        // Given back as it was, with the attempt counted, and nothing recorded.
        $row = $this->messageRow($id);
        self::assertSame(['received', 1, null], [$row['status'], self::int($row['attempts']), $row['processed_at']]);
        self::assertSame([], $this->movementsOf($id));
        self::assertSame(InboundStatus::Processed, $this->process->process($id));
        self::assertCount(1, $this->movementsOf($id));
    }

    public function testNoticesAppearForFailuresAndUnmatched(): void
    {
        $before = $this->notices(staff: true);
        self::assertSame([], $before);

        $this->process->process($this->received($this->notice('incoming-unknown-status')));
        $this->process->process($this->received($this->notice('incoming-booked', auth: 'aligned-dkim-fail', messageId: 'forged@kb.example.com')));
        // No invoice has its symbol: recorded and unmatched.
        $this->process->process($this->received($this->notice('incoming-booked')));

        $notices = $this->notices(staff: true);
        self::assertSame([
            ['error', '2 bank e-mails could not be processed. Check them and try again.', '/bank?tab=messages'],
            ['info', 'An incoming payment is not paired with an invoice.', '/bank?tab=unmatched'],
        ], array_map(static fn (ShellNotice $notice): array => [$notice->tone, $notice->text, $notice->href], $notices));
        self::assertSame([], $this->notices(staff: false));
        // Until the bank screen exists there is nowhere to lead.
        self::assertSame([], iterator_to_array($this->bankNotices(true, self::getContainer()->get(UrlGeneratorInterface::class))->notices(), false));
    }

    /**
     * Built here: until the bank screen uses it, the container keeps no such service.
     */
    private function accept(): AcceptRejectedMessage
    {
        return new AcceptRejectedMessage(self::getContainer()->get(InboundMessages::class), $this->process);
    }

    /**
     * @return list<ShellNotice>
     */
    private function notices(bool $staff): array
    {
        $urls = new class implements UrlGeneratorInterface {
            /**
             * @param array<mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/bank?'.http_build_query($parameters);
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };

        return iterator_to_array($this->bankNotices($staff, $urls)->notices(), false);
    }

    private function bankNotices(bool $staff, UrlGeneratorInterface $urls): BankNotices
    {
        $container = self::getContainer();
        $context = $container->get(TenantContext::class);
        $memberships = new readonly class($context->current(), $staff) implements MembershipDirectory {
            public function __construct(private TenantId $tenant, private bool $staff)
            {
            }

            public function tenantIdsOf(User $user): array
            {
                return [$this->tenant];
            }

            public function organisationsOf(string $userId): array
            {
                return [new Organisation($this->tenant, 'Example Firm', $this->staff ? Role::Member : Role::Client)];
            }
        };
        $actor = new class implements AuditActor {
            public function userId(): string
            {
                return '01963c5e-0000-7000-8000-0000000000aa';
            }
        };
        $translator = $container->get(TranslatorInterface::class);
        $translator->setLocale('en');

        return new BankNotices($container->get(InboundMessages::class), $container->get(BankMovements::class), new StaffCheck($memberships, $context, $actor), $urls, $translator, new RequestStack());
    }
}
