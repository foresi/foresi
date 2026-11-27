<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Country;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Audit\Application\AuditTrail;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Country\CzechRepublic\Party\CzechBankAccount;
use Foresi\Party\Application\BankAccountRefusal;
use Foresi\Party\Application\BankAccountRefused;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\ManageFirmBankAccounts;
use Foresi\Party\Contract\BankAccountUsage;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\TransactionalWork;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The firm's bank accounts are records: one of the active ones is primary, an
 * IBAN is there once, and an account is archived rather than deleted.
 * Every test stores, clears the entity manager and reads the rows back.
 */
final class ManageFirmBankAccountsTest extends KernelTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string SECOND_IBAN = 'CZ5508000000001234567899';
    private const string THIRD_IBAN = 'DE89370400440532013000';

    private EntityManagerInterface $em;
    private ManageFirmBankAccounts $manage;
    private bool $movements = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $usage = new readonly class($this) implements BankAccountUsage {
            public function __construct(private ManageFirmBankAccountsTest $test)
            {
            }

            public function hasMovements(string $accountId): bool
            {
                return $this->test->movements();
            }
        };
        $this->manage = new ManageFirmBankAccounts(
            $container->get(InstanceFirm::class),
            $container->get(FirmBankAccounts::class),
            $container->get(TransactionalWork::class),
            $usage,
            $container->get(ClockInterface::class),
            [new CzechBankAccount()],
        );
    }

    public function movements(): bool
    {
        return $this->movements;
    }

    public function testTheFirstAccountBecomesPrimaryAndOnlyOneStaysPrimary(): void
    {
        $this->firm();

        $first = $this->manage->open(self::IBAN, 'CZK', 'Main', false);
        $second = $this->manage->open(self::SECOND_IBAN, 'EUR', 'Euro', true);
        $third = $this->manage->open(self::THIRD_IBAN, 'CZK', '', false);
        $this->em->clear();

        self::assertSame([[self::SECOND_IBAN, true, 'EUR', 'Euro', 1], [self::IBAN, false, 'CZK', 'Main', 0], [self::THIRD_IBAN, false, 'CZK', '', 2]], $this->stored());

        $this->manage->change($third->id(), 'Third', 'CZK', true);
        $this->em->clear();

        self::assertSame([[self::THIRD_IBAN, true, 'CZK', 'Third', 2], [self::IBAN, false, 'CZK', 'Main', 0], [self::SECOND_IBAN, false, 'EUR', 'Euro', 1]], $this->stored());
        self::assertSame($third->id(), $this->accounts()->primary()?->id());
        self::assertNotSame($first->id(), $second->id());
    }

    public function testOpeningLocksTheFirmBeforeReadingItsAccounts(): void
    {
        // Two requests opening the first account at once would both find no
        // primary and both mark theirs: the firm's row lock makes the second wait.
        $this->firm();
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();

        $this->recording($calls)->open(self::IBAN, 'CZK', 'Main', false);

        $order = $calls->getArrayCopy();
        self::assertSame('lock firm', $order[0] ?? null, implode(', ', $order));
        self::assertNotContains('get firm', $order);
        $this->em->clear();
        self::assertSame([[self::IBAN, true, 'CZK', 'Main', 0]], $this->stored());
    }

    public function testChangingTheCurrencyLocksTheAccountBeforeLookingForMovements(): void
    {
        // A movement recorded meanwhile takes the account's lock too: it either
        // waits for the change or is seen by it, and the currency stays.
        $this->firm();
        $account = $this->manage->open(self::IBAN, 'CZK', 'Main', false);
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();

        $this->recording($calls)->change($account->id(), 'Main', 'EUR', false);

        self::assertSame(['lock account', 'movements?'], $calls->getArrayCopy());
        $this->em->clear();
        self::assertSame('EUR', $this->accounts()->get($account->id())->currency()->value);
    }

    /**
     * Writes what it reads and locks to the calls, in order.
     *
     * @param \ArrayObject<int, string> $calls
     */
    private function recording(\ArrayObject $calls): ManageFirmBankAccounts
    {
        $container = self::getContainer();
        $firms = new readonly class($container->get(LegalEntities::class), $calls) implements LegalEntities {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private LegalEntities $inner, private \ArrayObject $calls)
            {
            }

            public function all(): array
            {
                return $this->inner->all();
            }

            public function get(string $id): LegalEntity
            {
                $this->calls->append('get firm');

                return $this->inner->get($id);
            }

            public function lock(string $id): LegalEntity
            {
                $this->calls->append('lock firm');

                return $this->inner->lock($id);
            }

            public function add(LegalEntity $firm): void
            {
                $this->inner->add($firm);
            }
        };
        $accounts = new readonly class($container->get(FirmBankAccounts::class), $calls) implements FirmBankAccounts {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private FirmBankAccounts $inner, private \ArrayObject $calls)
            {
            }

            public function all(bool $withArchived = false): array
            {
                $this->calls->append('read accounts');

                return $this->inner->all($withArchived);
            }

            public function primary(): ?FirmBankAccount
            {
                $this->calls->append('read accounts');

                return $this->inner->primary();
            }

            public function ibans(): array
            {
                $this->calls->append('read accounts');

                return $this->inner->ibans();
            }

            public function get(string $id): FirmBankAccount
            {
                return $this->inner->get($id);
            }

            public function ofIban(string $iban): ?FirmBankAccount
            {
                $this->calls->append('read accounts');

                return $this->inner->ofIban($iban);
            }

            public function nextPosition(): int
            {
                $this->calls->append('read accounts');

                return $this->inner->nextPosition();
            }

            public function add(FirmBankAccount $account): void
            {
                $this->inner->add($account);
            }

            public function makePrimary(FirmBankAccount $account): void
            {
                $this->inner->makePrimary($account);
            }

            public function lock(string $id): FirmBankAccount
            {
                $this->calls->append('lock account');

                return $this->inner->lock($id);
            }
        };

        return new ManageFirmBankAccounts(new InstanceFirm($firms), $accounts, $container->get(TransactionalWork::class), new readonly class($calls) implements BankAccountUsage {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function hasMovements(string $accountId): bool
            {
                $this->calls->append('movements?');

                return false;
            }
        }, $container->get(ClockInterface::class), [new CzechBankAccount()]);
    }

    public function testUnmarkingThePrimaryKeepsIt(): void
    {
        $this->firm();
        $account = $this->manage->open(self::IBAN, 'CZK', '', false);

        $this->manage->change($account->id(), 'Still main', 'CZK', false);
        $this->em->clear();

        self::assertSame([[self::IBAN, true, 'CZK', 'Still main', 0]], $this->stored());
    }

    public function testADomesticNumberIsStoredAsIban(): void
    {
        $this->firm();

        $this->manage->open(' 19-2000145399/0800 ', 'CZK', '', false);
        $this->em->clear();

        self::assertSame([[self::IBAN, true, 'CZK', '', 0]], $this->stored());
    }

    public function testAnIbanIsNormalised(): void
    {
        $this->firm();

        $this->manage->open('cz65 0800 0000 1920 0014 5399', 'CZK', '', false);
        $this->em->clear();

        self::assertSame(self::IBAN, $this->stored()[0][0]);
    }

    public function testAnInvalidNumberIsRefused(): void
    {
        $this->firm();

        foreach (['CZ00 nonsense', '19-2000145399/08000', '', 'CZ6508000000192000145398'] as $number) {
            $this->assertRefused(BankAccountRefusal::InvalidIban, fn (): FirmBankAccount => $this->manage->open($number, 'CZK', '', false));
        }
        $this->em->clear();
        self::assertSame([], $this->stored());
    }

    public function testAnUnknownCurrencyIsRefused(): void
    {
        $this->firm();
        $this->assertRefused(BankAccountRefusal::UnknownCurrency, fn (): FirmBankAccount => $this->manage->open(self::IBAN, 'GBP', '', false));
        $account = $this->manage->open(self::IBAN, 'CZK', '', false);

        $this->assertRefused(BankAccountRefusal::UnknownCurrency, fn (): FirmBankAccount => $this->manage->change($account->id(), '', 'czk', true));
        $this->em->clear();
        self::assertSame([[self::IBAN, true, 'CZK', '', 0]], $this->stored());
    }

    public function testTheSameIbanTwiceIsRefused(): void
    {
        $this->firm();
        $account = $this->manage->open(self::IBAN, 'CZK', '', false);

        $this->assertRefused(BankAccountRefusal::Duplicate, fn (): FirmBankAccount => $this->manage->open('19-2000145399/0800', 'EUR', '', false));
        $this->manage->archive($account->id());
        $this->assertRefused(BankAccountRefusal::Duplicate, fn (): FirmBankAccount => $this->manage->open(self::IBAN, 'CZK', '', false));
        $this->em->clear();

        self::assertCount(1, $this->stored(true));
    }

    public function testCurrencyIsLockedOnceMovementsExist(): void
    {
        $this->firm();
        $account = $this->manage->open(self::IBAN, 'CZK', '', false);
        $this->manage->change($account->id(), '', 'EUR', true);

        $this->movements = true;
        $this->assertRefused(BankAccountRefusal::CurrencyLocked, fn (): FirmBankAccount => $this->manage->change($account->id(), 'Renamed', 'CZK', true));
        $this->manage->change($account->id(), 'Renamed', 'EUR', true);
        $this->em->clear();

        self::assertSame([[self::IBAN, true, 'EUR', 'Renamed', 0]], $this->stored());
    }

    public function testArchivingThePrimaryMovesPrimaryToTheNext(): void
    {
        $this->firm();
        $first = $this->manage->open(self::IBAN, 'CZK', '', false);
        $this->manage->open(self::SECOND_IBAN, 'CZK', '', false);
        $this->manage->open(self::THIRD_IBAN, 'CZK', '', false);

        $this->manage->archive($first->id());
        $this->em->clear();

        self::assertSame([[self::SECOND_IBAN, true, 'CZK', '', 1], [self::THIRD_IBAN, false, 'CZK', '', 2]], $this->stored());
        self::assertSame([self::SECOND_IBAN, self::THIRD_IBAN], $this->accounts()->ibans());
        $archived = $this->accounts()->get($first->id());
        self::assertTrue($archived->isArchived());
        self::assertFalse($archived->isPrimary());
        self::assertNotNull($this->accounts()->ofIban(self::IBAN), 'an archived account is still found by its IBAN');
    }

    public function testArchivingTheLastAccountLeavesNoPrimary(): void
    {
        $this->firm();
        $account = $this->manage->open(self::IBAN, 'CZK', '', false);

        $this->manage->archive($account->id());
        $this->manage->archive($account->id());
        $this->em->clear();

        self::assertSame([], $this->stored());
        self::assertNull($this->accounts()->primary());
        self::assertSame([], $this->accounts()->ibans());
    }

    public function testAnArchivedAccountCannotBecomePrimary(): void
    {
        $this->firm();
        $this->manage->open(self::IBAN, 'CZK', '', false);
        $second = $this->manage->open(self::SECOND_IBAN, 'CZK', '', false);
        $this->manage->archive($second->id());

        $this->assertRefused(BankAccountRefusal::ArchivedPrimary, fn (): FirmBankAccount => $this->manage->change($second->id(), '', 'CZK', true));
        $this->manage->change($second->id(), 'Old', 'CZK', false);
        $this->em->clear();

        self::assertSame([[self::IBAN, true, 'CZK', '', 0]], $this->stored());
        self::assertSame('Old', $this->accounts()->get($second->id())->label());
    }

    public function testRestoringBringsTheAccountBackAndMakesItPrimaryWhenNoneIs(): void
    {
        $this->firm();
        $first = $this->manage->open(self::IBAN, 'CZK', '', false);
        $second = $this->manage->open(self::SECOND_IBAN, 'CZK', '', false);
        $this->manage->archive($first->id());
        $this->manage->archive($second->id());

        $this->manage->restore($second->id());
        $this->manage->restore($first->id());
        $this->manage->restore($first->id());
        $this->em->clear();

        self::assertSame([[self::SECOND_IBAN, true, 'CZK', '', 1], [self::IBAN, false, 'CZK', '', 0]], $this->stored());
    }

    public function testTheListPutsThePrimaryFirstAndThenFollowsThePosition(): void
    {
        $this->firm();
        $first = $this->manage->open(self::IBAN, 'CZK', '', false);
        $this->manage->open(self::SECOND_IBAN, 'CZK', '', false);
        $third = $this->manage->open(self::THIRD_IBAN, 'CZK', '', true);
        $this->manage->archive($first->id());
        $this->em->clear();

        self::assertSame([self::THIRD_IBAN, self::SECOND_IBAN], array_map(static fn (FirmBankAccount $a): string => $a->iban(), $this->accounts()->all()));
        self::assertSame([self::THIRD_IBAN, self::IBAN, self::SECOND_IBAN], array_map(static fn (FirmBankAccount $a): string => $a->iban(), $this->accounts()->all(withArchived: true)));
        self::assertSame($third->id(), $this->accounts()->primary()?->id());
    }

    public function testTheAccountIsItsOwnAuditRoot(): void
    {
        $this->firm();
        $account = $this->manage->open(self::IBAN, 'CZK', 'Main', false);
        $this->manage->change($account->id(), 'Renamed', 'CZK', true);
        $this->em->clear();

        $entries = self::getContainer()->get(AuditTrail::class)->rootHistory(FirmBankAccount::class, $account->id());

        self::assertCount(2, $entries);
        self::assertSame($account->id(), $entries[0]->entityId());
        self::assertSame(AuditAction::Created, $entries[0]->action());
        self::assertSame(AuditAction::Updated, $entries[1]->action());
        self::assertSame(['Main', 'Renamed'], $entries[1]->changes()['label']);
    }

    /**
     * @param callable(): FirmBankAccount $attempt
     */
    private function assertRefused(BankAccountRefusal $reason, callable $attempt): void
    {
        try {
            $attempt();
            self::fail(\sprintf('Expected the refusal %s.', $reason->name));
        } catch (BankAccountRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    /**
     * The stored accounts as read back, in list order.
     *
     * @return list<array{string, bool, string, string, int}>
     */
    private function stored(bool $withArchived = false): array
    {
        return array_map(
            static fn (FirmBankAccount $account): array => [$account->iban(), $account->isPrimary(), $account->currency()->value, $account->label(), $account->position()],
            $this->accounts()->all($withArchived),
        );
    }

    private function accounts(): FirmBankAccounts
    {
        return self::getContainer()->get(FirmBankAccounts::class);
    }

    private function firm(): void
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($firm);
        $this->em->flush();
    }
}
