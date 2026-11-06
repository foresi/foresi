<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Domain;

use App\Idoklad\Domain\IdokladConnection;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladJob;
use App\Idoklad\Domain\IdokladJobKind;
use App\Idoklad\Domain\IdokladLink;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportMode;
use App\Idoklad\Domain\ImportReport;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Domain\TenantOwned;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdokladEntitiesTest extends TestCase
{
    public function testOnlyARunSetsTheDayRemindersStartFrom(): void
    {
        $run = new IdokladJob(self::firm(), IdokladJobKind::Run, new \DateTimeImmutable('2026-11-06'));
        self::assertNull($run->remindFrom());

        $this->expectException(\InvalidArgumentException::class);
        new IdokladJob(self::firm(), IdokladJobKind::Pull, new \DateTimeImmutable('2026-11-06'), new \DateTimeImmutable('2026-11-02'));
    }

    public function testTheErrorOfAJobAndOfARunIsCutToTheColumnInCharacters(): void
    {
        $job = new IdokladJob(self::firm(), IdokladJobKind::Pull, new \DateTimeImmutable('2026-11-06'));
        $job->finish(new \DateTimeImmutable('2026-11-06 10:00:00'), null, str_repeat('é', 300));
        self::assertSame(str_repeat('é', 255), $job->error());

        $run = new IdokladImportRun(self::firm(), ImportMode::Check, new \DateTimeImmutable('2026-11-06'));
        $run->finish(new \DateTimeImmutable('2026-11-06 10:00:00'), new ImportReport(), str_repeat('é', 300));
        self::assertSame(str_repeat('é', 255), $run->error());
    }

    public function testAClientIdOf255CharactersIsCountedInCharacters(): void
    {
        self::assertSame(str_repeat('č', 255), new IdokladConnection(self::firm(), str_repeat('č', 255), 'sealed')->clientId());
    }

    public function testARecordKeepsSlashesAndAccentsAsTheyAre(): void
    {
        $record = new IdokladRecord(self::firm(), IdokladResource::Contacts, 1, ['Www' => 'https://example.com/', 'Name' => 'Česká'], new \DateTimeImmutable('2026-11-06'));
        $stored = new \ReflectionProperty(IdokladRecord::class, 'payload')->getValue($record);

        self::assertSame('{"Www":"https://example.com/","Name":"Česká"}', $stored);
    }

    public function testOnlyTheDocumentsHaveAPdfAndSomeListsNoChangeFilter(): void
    {
        $reports = [];
        $filters = [];
        foreach (IdokladResource::cases() as $resource) {
            $reports[$resource->value] = $resource->pdfReport();
            $filters[$resource->value] = $resource->changeFilter();
        }

        self::assertSame(['IssuedInvoices' => 'IssuedInvoice', 'ProformaInvoices' => 'ProformaInvoice', 'IssuedTaxDocuments' => 'IssuedTaxDocument', 'CreditNotes' => 'CreditNote'], array_filter($reports, static fn (?string $report): bool => null !== $report));
        self::assertSame(['NumericSequences', 'IssuedDocumentPayments', 'Countries', 'Currencies', 'PaymentOptions', 'ConstantSymbols', 'BankAccounts'], array_keys(array_filter($filters, static fn (?string $filter): bool => null === $filter)));
        self::assertSame('DateFrom', IdokladResource::Logs->changeFilter());
    }

    /**
     * @return iterable<string, array{\Closure(): TenantOwned}>
     */
    public static function unstamped(): iterable
    {
        yield 'connection' => [static fn (): TenantOwned => new IdokladConnection(self::firm(), 'example-client', 'sealed')];
        yield 'import run' => [static fn (): TenantOwned => new IdokladImportRun(self::firm(), ImportMode::Run, new \DateTimeImmutable())];
        yield 'job' => [static fn (): TenantOwned => new IdokladJob(self::firm(), IdokladJobKind::Pull, new \DateTimeImmutable())];
        yield 'link' => [static fn (): TenantOwned => new IdokladLink(self::firm(), IdokladResource::Contacts, 1, 'target', 'run', new \DateTimeImmutable())];
        yield 'pull' => [static fn (): TenantOwned => new IdokladPull(self::firm(), new \DateTimeImmutable())];
        yield 'record' => [static fn (): TenantOwned => new IdokladRecord(self::firm(), IdokladResource::Contacts, 1, [], new \DateTimeImmutable())];
    }

    /**
     * @param \Closure(): TenantOwned $entity
     */
    #[DataProvider('unstamped')]
    public function testTheTenantIsKnownOnlyOncePersisted(\Closure $entity): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The tenant is stamped on persist.');
        $entity()->tenantId();
    }

    private static function firm(): LegalEntity
    {
        return new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '12345678'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
    }
}
