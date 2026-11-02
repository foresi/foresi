<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Country\CzechRepublic\Party\CzechBankAccount;
use App\Party\Application\BankAccountRefusal;
use App\Party\Application\BankAccountRefused;
use App\Party\Application\BankAccountUsage;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\LegalEntities;
use App\Party\Application\ManageFirmBankAccounts;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownFirmBankAccount;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\Address;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A firm's bank accounts are records: one of the active ones is primary, an
 * IBAN is there once per firm, and an account is archived rather than deleted.
 * Every test stores, clears the entity manager and reads the rows back.
 */
final class ManageFirmBankAccountsTest extends KernelTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string SECOND_IBAN = 'CZ5508000000001234567899';
    private const string THIRD_IBAN = 'DE89370400440532013000';

    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;
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
        $this->context = $container->get(TenantContext::class);
        $this->tenant = TenantId::generate();
        $this->context->switchTo($this->tenant);

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
            $container->get(LegalEntities::class),
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
        $firm = $this->firm();

        $first = $this->manage->open($firm->id(), self::IBAN, 'CZK', 'Main', false);
        $second = $this->manage->open($firm->id(), self::SECOND_IBAN, 'EUR', 'Euro', true);
        $third = $this->manage->open($firm->id(), self::THIRD_IBAN, 'CZK', '', false);
        $this->em->clear();

        self::assertSame([[self::SECOND_IBAN, true, 'EUR', 'Euro', 1], [self::IBAN, false, 'CZK', 'Main', 0], [self::THIRD_IBAN, false, 'CZK', '', 2]], $this->stored($firm));

        $this->manage->change($third->id(), 'Third', 'CZK', true);
        $this->em->clear();

        self::assertSame([[self::THIRD_IBAN, true, 'CZK', 'Third', 2], [self::IBAN, false, 'CZK', 'Main', 0], [self::SECOND_IBAN, false, 'EUR', 'Euro', 1]], $this->stored($firm));
        self::assertSame($third->id(), $this->accounts()->primaryOf($this->reloaded($firm))?->id());
        self::assertNotSame($first->id(), $second->id());
    }

    public function testOpeningLocksTheFirmBeforeReadingItsAccounts(): void
    {
        // Two requests opening a firm's first account at once would both find no
        // primary and both mark theirs: the firm's row lock makes the second wait.
        $firm = $this->firm();
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
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

            public function active(): array
            {
                return $this->inner->active();
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

            public function lockActive(): array
            {
                return $this->inner->lockActive();
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

            public function of(LegalEntity $firm, bool $withArchived = false): array
            {
                $this->calls->append('read accounts');

                return $this->inner->of($firm, $withArchived);
            }

            public function primaryOf(LegalEntity $firm): ?FirmBankAccount
            {
                $this->calls->append('read accounts');

                return $this->inner->primaryOf($firm);
            }

            public function ibansOf(LegalEntity $firm): array
            {
                $this->calls->append('read accounts');

                return $this->inner->ibansOf($firm);
            }

            public function get(string $id): FirmBankAccount
            {
                return $this->inner->get($id);
            }

            public function ofIban(LegalEntity $firm, string $iban): ?FirmBankAccount
            {
                $this->calls->append('read accounts');

                return $this->inner->ofIban($firm, $iban);
            }

            public function nextPosition(LegalEntity $firm): int
            {
                $this->calls->append('read accounts');

                return $this->inner->nextPosition($firm);
            }

            public function add(FirmBankAccount $account): void
            {
                $this->inner->add($account);
            }

            public function makePrimary(FirmBankAccount $account): void
            {
                $this->inner->makePrimary($account);
            }
        };
        $manage = new ManageFirmBankAccounts($firms, $accounts, $container->get(TransactionalWork::class), new readonly class implements BankAccountUsage {
            public function hasMovements(string $accountId): bool
            {
                return false;
            }
        }, $container->get(ClockInterface::class), [new CzechBankAccount()]);

        $manage->open($firm->id(), self::IBAN, 'CZK', 'Main', false);

        $order = $calls->getArrayCopy();
        self::assertSame('lock firm', $order[0] ?? null, implode(', ', $order));
        self::assertNotContains('get firm', $order);
        $this->em->clear();
        self::assertSame([[self::IBAN, true, 'CZK', 'Main', 0]], $this->stored($firm));
    }

    public function testUnmarkingThePrimaryKeepsIt(): void
    {
        $firm = $this->firm();
        $account = $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);

        $this->manage->change($account->id(), 'Still main', 'CZK', false);
        $this->em->clear();

        self::assertSame([[self::IBAN, true, 'CZK', 'Still main', 0]], $this->stored($firm));
    }

    public function testADomesticNumberIsStoredAsIban(): void
    {
        $firm = $this->firm();

        $this->manage->open($firm->id(), ' 19-2000145399/0800 ', 'CZK', '', false);
        $this->em->clear();

        self::assertSame([[self::IBAN, true, 'CZK', '', 0]], $this->stored($firm));
    }

    public function testAnIbanIsNormalised(): void
    {
        $firm = $this->firm();

        $this->manage->open($firm->id(), 'cz65 0800 0000 1920 0014 5399', 'CZK', '', false);
        $this->em->clear();

        self::assertSame(self::IBAN, $this->stored($firm)[0][0]);
    }

    public function testAnInvalidNumberIsRefused(): void
    {
        $firm = $this->firm();

        foreach (['CZ00 nonsense', '19-2000145399/08000', '', 'CZ6508000000192000145398'] as $number) {
            $this->assertRefused(BankAccountRefusal::InvalidIban, fn (): FirmBankAccount => $this->manage->open($firm->id(), $number, 'CZK', '', false));
        }
        $this->em->clear();
        self::assertSame([], $this->stored($firm));
    }

    public function testAnUnknownCurrencyIsRefused(): void
    {
        $firm = $this->firm();
        $this->assertRefused(BankAccountRefusal::UnknownCurrency, fn (): FirmBankAccount => $this->manage->open($firm->id(), self::IBAN, 'GBP', '', false));
        $account = $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);

        $this->assertRefused(BankAccountRefusal::UnknownCurrency, fn (): FirmBankAccount => $this->manage->change($account->id(), '', 'czk', true));
        $this->em->clear();
        self::assertSame([[self::IBAN, true, 'CZK', '', 0]], $this->stored($firm));
    }

    public function testTheSameIbanTwiceInOneFirmIsRefused(): void
    {
        $firm = $this->firm();
        $other = $this->firm('Example Other s.r.o.', '12345687');
        $account = $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);

        $this->assertRefused(BankAccountRefusal::Duplicate, fn (): FirmBankAccount => $this->manage->open($firm->id(), '19-2000145399/0800', 'EUR', '', false));
        $this->manage->archive($account->id());
        $this->assertRefused(BankAccountRefusal::Duplicate, fn (): FirmBankAccount => $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false));
        $this->manage->open($other->id(), self::IBAN, 'CZK', '', false);
        $this->em->clear();

        self::assertCount(1, $this->stored($firm, true));
        self::assertSame([[self::IBAN, true, 'CZK', '', 0]], $this->stored($other));
    }

    public function testCurrencyIsLockedOnceMovementsExist(): void
    {
        $firm = $this->firm();
        $account = $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);
        $this->manage->change($account->id(), '', 'EUR', true);

        $this->movements = true;
        $this->assertRefused(BankAccountRefusal::CurrencyLocked, fn (): FirmBankAccount => $this->manage->change($account->id(), 'Renamed', 'CZK', true));
        $this->manage->change($account->id(), 'Renamed', 'EUR', true);
        $this->em->clear();

        self::assertSame([[self::IBAN, true, 'EUR', 'Renamed', 0]], $this->stored($firm));
    }

    public function testArchivingThePrimaryMovesPrimaryToTheNext(): void
    {
        $firm = $this->firm();
        $first = $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);
        $this->manage->open($firm->id(), self::SECOND_IBAN, 'CZK', '', false);
        $this->manage->open($firm->id(), self::THIRD_IBAN, 'CZK', '', false);

        $this->manage->archive($first->id());
        $this->em->clear();

        self::assertSame([[self::SECOND_IBAN, true, 'CZK', '', 1], [self::THIRD_IBAN, false, 'CZK', '', 2]], $this->stored($firm));
        self::assertSame([self::SECOND_IBAN, self::THIRD_IBAN], $this->accounts()->ibansOf($this->reloaded($firm)));
        $archived = $this->accounts()->get($first->id());
        self::assertTrue($archived->isArchived());
        self::assertFalse($archived->isPrimary());
        self::assertNotNull($this->accounts()->ofIban($this->reloaded($firm), self::IBAN), 'an archived account is still found by its IBAN');
    }

    public function testArchivingTheLastAccountLeavesNoPrimary(): void
    {
        $firm = $this->firm();
        $account = $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);

        $this->manage->archive($account->id());
        $this->manage->archive($account->id());
        $this->em->clear();

        self::assertSame([], $this->stored($firm));
        self::assertNull($this->accounts()->primaryOf($this->reloaded($firm)));
        self::assertSame([], $this->accounts()->ibansOf($this->reloaded($firm)));
    }

    public function testAnArchivedAccountCannotBecomePrimary(): void
    {
        $firm = $this->firm();
        $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);
        $second = $this->manage->open($firm->id(), self::SECOND_IBAN, 'CZK', '', false);
        $this->manage->archive($second->id());

        $this->assertRefused(BankAccountRefusal::ArchivedPrimary, fn (): FirmBankAccount => $this->manage->change($second->id(), '', 'CZK', true));
        $this->manage->change($second->id(), 'Old', 'CZK', false);
        $this->em->clear();

        self::assertSame([[self::IBAN, true, 'CZK', '', 0]], $this->stored($firm));
        self::assertSame('Old', $this->accounts()->get($second->id())->label());
    }

    public function testRestoringBringsTheAccountBackAndMakesItPrimaryWhenNoneIs(): void
    {
        $firm = $this->firm();
        $first = $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);
        $second = $this->manage->open($firm->id(), self::SECOND_IBAN, 'CZK', '', false);
        $this->manage->archive($first->id());
        $this->manage->archive($second->id());

        $this->manage->restore($second->id());
        $this->manage->restore($first->id());
        $this->manage->restore($first->id());
        $this->em->clear();

        self::assertSame([[self::SECOND_IBAN, true, 'CZK', '', 1], [self::IBAN, false, 'CZK', '', 0]], $this->stored($firm));
    }

    public function testTheListPutsThePrimaryFirstAndThenFollowsThePosition(): void
    {
        $firm = $this->firm();
        $first = $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);
        $this->manage->open($firm->id(), self::SECOND_IBAN, 'CZK', '', false);
        $third = $this->manage->open($firm->id(), self::THIRD_IBAN, 'CZK', '', true);
        $this->manage->archive($first->id());
        $this->em->clear();

        $firm = $this->reloaded($firm);
        self::assertSame([self::THIRD_IBAN, self::SECOND_IBAN], array_map(static fn (FirmBankAccount $a): string => $a->iban(), $this->accounts()->of($firm)));
        self::assertSame([self::THIRD_IBAN, self::IBAN, self::SECOND_IBAN], array_map(static fn (FirmBankAccount $a): string => $a->iban(), $this->accounts()->of($firm, withArchived: true)));
        self::assertSame($third->id(), $this->accounts()->primaryOf($firm)?->id());
    }

    public function testAnotherTenantsAccountIsNotFound(): void
    {
        $firm = $this->firm();
        $account = $this->manage->open($firm->id(), self::IBAN, 'CZK', '', false);
        $this->em->clear();

        $this->context->switchTo(TenantId::generate());
        $ours = $this->firm();

        try {
            $this->accounts()->get($account->id());
            self::fail('Another tenant\'s account was found.');
        } catch (UnknownFirmBankAccount) {
        }
        foreach ([fn (): FirmBankAccount => $this->manage->change($account->id(), 'Taken', 'CZK', true), fn () => $this->manage->archive($account->id()), fn () => $this->manage->restore($account->id())] as $attempt) {
            try {
                $attempt();
                self::fail('Another tenant\'s account was changed.');
            } catch (UnknownFirmBankAccount) {
            }
        }
        try {
            $this->manage->open($firm->id(), self::SECOND_IBAN, 'CZK', '', false);
            self::fail('An account was opened for another tenant\'s firm.');
        } catch (UnknownLegalEntity) {
        }
        self::assertSame([], $this->accounts()->of($ours));
        self::assertNull($this->accounts()->ofIban($ours, self::IBAN));

        $this->context->switchTo($this->tenant);
        $this->em->clear();
        self::assertSame([[self::IBAN, true, 'CZK', '', 0]], $this->stored($firm));
    }

    public function testAuditHistoryOfTheFirmShowsTheAccount(): void
    {
        $firm = $this->firm();
        $account = $this->manage->open($firm->id(), self::IBAN, 'CZK', 'Main', false);
        $this->manage->change($account->id(), 'Renamed', 'CZK', true);
        $this->em->clear();

        $entries = array_values(array_filter(
            self::getContainer()->get(AuditTrail::class)->rootHistory(LegalEntity::class, $firm->id()),
            static fn (AuditEntry $entry): bool => FirmBankAccount::class === $entry->entityClass(),
        ));

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
     * The stored accounts of the firm as read back, in list order.
     *
     * @return list<array{string, bool, string, string, int}>
     */
    private function stored(LegalEntity $firm, bool $withArchived = false): array
    {
        return array_map(
            static fn (FirmBankAccount $account): array => [$account->iban(), $account->isPrimary(), $account->currency()->value, $account->label(), $account->position()],
            $this->accounts()->of($this->reloaded($firm), $withArchived),
        );
    }

    private function reloaded(LegalEntity $firm): LegalEntity
    {
        return self::getContainer()->get(LegalEntities::class)->get($firm->id());
    }

    private function accounts(): FirmBankAccounts
    {
        return self::getContainer()->get(FirmBankAccounts::class);
    }

    private function firm(string $name = 'Example Firm s.r.o.', string $number = '12345695'): LegalEntity
    {
        $firm = new LegalEntity($name, new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($firm);
        $this->em->flush();

        return $firm;
    }
}
