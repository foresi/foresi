<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Integration\DocumentTemplates;

use Foresi\Contacts\Application\LinkContactPerson;
use Foresi\Contacts\Integration\DocumentTemplates\ClientVariables;
use Foresi\Contacts\Integration\DocumentTemplates\FirmVariables;
use Foresi\DocumentTemplates\Application\DocumentVariables;
use Foresi\DocumentTemplates\Contract\RecordLink;
use Foresi\DocumentTemplates\Contract\VariableDefinition;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\FirmImages;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\Party\Application\Query\ContactTestCase;

/**
 * The firm and the client as a document template reads them: {{ firm.* }} from
 * the firm of the instance, {{ client.* }} from the contact a document links to.
 */
final class DocumentVariableSourcesTest extends ContactTestCase
{
    public function testFirmValuesComeFromInstanceFirm(): void
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Firm Street 7', 'Example City', '110 00', 'CZ'), VatStatus::Registered, '');
        $this->em->persist($firm);
        $other = FirmBankAccount::open(Iban::fromString('CZ5508000000001234567899'), Currency::CZK, 'Savings', 2);
        $primary = FirmBankAccount::open(Iban::fromString('CZ6508000000192000145399'), Currency::CZK, 'Main', 1);
        $primary->markPrimary(true);
        $this->em->persist($other);
        $this->em->persist($primary);
        $this->em->flush();

        $source = $this->firm();

        self::assertSame('firm', $source->prefix());
        self::assertSame(['name', 'address_lines', 'registration_number', 'vat_id', 'bank_account', 'logo'], $this->names($source->definitions()));
        self::assertSame([
            'name' => 'Example Firm s.r.o.',
            'address_lines' => [['line' => 'Firm Street 7'], ['line' => '110 00 Example City'], ['line' => 'CZ']],
            'registration_number' => '12345695',
            'vat_id' => 'CZ12345695',
            'bank_account' => 'CZ65 0800 0000 1920 0014 5399',
            'logo' => '',
        ], $source->values([]));

        // Read when asked, never copied: a renamed firm shows its new name.
        $firm->update('Renamed Firm s.r.o.', $firm->registrationNumber(), null, $firm->address(), VatStatus::NotRegistered, '');
        $this->em->flush();
        $values = $source->values([new RecordLink('contact', '0192f1a4-0000-7000-8000-000000000000')]);
        self::assertSame('Renamed Firm s.r.o.', $values['name']);
        self::assertSame('', $values['vat_id']);
    }

    public function testTheLogoIsTheNameOfTheStoredImageAndItsFileIsLocal(): void
    {
        $images = self::getContainer()->get(FirmImages::class);
        $name = $images->store(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgYPgPAAEDAQAIicLsAAAAAElFTkSuQmCC', true));
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Firm Street 7', 'Example City', '110 00', 'CZ'), VatStatus::NotRegistered, '');
        $firm->changeLogo($name);
        $this->em->persist($firm);
        $this->em->flush();

        $source = $this->firm();
        $logo = $source->definitions()[5];

        self::assertTrue($logo->image);
        self::assertSame('Logo firmy', $logo->label);
        self::assertSame($name, $source->values([])['logo']);
        self::assertSame($images->path($name), $source->imageFile('logo', $name));
        // Not a stored image, or not an image variable: no file.
        self::assertNull($source->imageFile('logo', '../../etc/passwd'));
        self::assertNull($source->imageFile('logo', str_repeat('0', 64).'.png'));
        self::assertNull($source->imageFile('name', $name));
    }

    public function testFirmValuesBeforeTheFirmExistsAreEmpty(): void
    {
        self::assertSame(['name' => '', 'address_lines' => [], 'registration_number' => '', 'vat_id' => '', 'bank_account' => '', 'logo' => ''], $this->firm()->values([]));
    }

    public function testClientValuesNeedAContactLink(): void
    {
        $client = $this->contact('Alpha Example s.r.o.', vatId: 'CZ12345679');
        $source = $this->client();

        self::assertSame('client', $source->prefix());
        self::assertSame(['name', 'address_lines', 'registration_number', 'vat_id', 'persons'], $this->names($source->definitions()));
        self::assertNull($source->values([]));
        self::assertNull($source->values([new RecordLink('deal', $client->id())]));
        // A contact that is gone gives no values; Documents then names the missing variables.
        self::assertNull($source->values([new RecordLink('contact', '0192f1a4-0000-7000-8000-000000000000')]));

        self::assertSame([
            'name' => 'Alpha Example s.r.o.',
            'address_lines' => [['line' => 'Example Street 1'], ['line' => '100 00 Example City'], ['line' => 'CZ']],
            'registration_number' => '12345679',
            'vat_id' => 'CZ12345679',
            'persons' => [],
        ], $source->values([new RecordLink('deal', '0192f1a4-0000-7000-8000-000000000001'), new RecordLink('contact', $client->id())]));
    }

    public function testPersonsListedInLinkOrder(): void
    {
        $company = $this->contact('Alpha Example s.r.o.', number: null, vatId: 'CZ12345679');
        $zuzana = $this->contact('Zuzana Example', PartyKind::Person, [], null, null, 'zuzana@example.com');
        $adam = $this->contact('Adam Example', PartyKind::Person, [], null);
        $link = self::getContainer()->get(LinkContactPerson::class);
        $link->link($company, $zuzana, 'Director', true);
        $link->link($company, $adam, '', false);

        $values = $this->client()->values([new RecordLink('contact', $company->id())]);

        self::assertNotNull($values);
        self::assertSame('', $values['registration_number']);
        self::assertSame([
            ['name' => 'Zuzana Example', 'position' => 'Director', 'email' => 'zuzana@example.com', 'phone' => '+420 000 000 000'],
            ['name' => 'Adam Example', 'position' => '', 'email' => '', 'phone' => '+420 000 000 000'],
        ], $values['persons']);
    }

    public function testDocumentsReadsBothSources(): void
    {
        $definitions = self::getContainer()->get(DocumentVariables::class)->definitions();

        self::assertArrayHasKey('firm', $definitions);
        self::assertArrayHasKey('client', $definitions);
        self::assertSame('Název klienta', $definitions['client'][0]->label);
    }

    public function testTheFirstContactLinkGivesTheClient(): void
    {
        $first = $this->contact('Alpha Example', PartyKind::Person, [], null);
        $second = $this->contact('Beta Example', PartyKind::Person, [], null);

        $values = $this->client()->values([new RecordLink('contact', $first->id()), new RecordLink('contact', $second->id())]);

        self::assertNotNull($values);
        self::assertSame('Alpha Example', $values['name']);
    }

    public function testTheExamplesShowWholeAddressesAndPersons(): void
    {
        $firm = $this->examples($this->firm()->definitions());
        $client = $this->examples($this->client()->definitions());

        self::assertSame([['line' => 'Example Street 1'], ['line' => '100 00 Example City'], ['line' => 'CZ']], $firm['address_lines']);
        self::assertSame([['line' => 'Client Street 2'], ['line' => '200 00 Client City'], ['line' => 'CZ']], $client['address_lines']);
        self::assertSame([['name' => 'Jana Example', 'position' => 'Director', 'email' => 'jana@example.com', 'phone' => '+420 000 000 000']], $client['persons']);
        self::assertSame('Název firmy', $this->firm()->definitions()[0]->label);
    }

    public function testExamplesFillEveryDefinition(): void
    {
        foreach ([...$this->firm()->definitions(), ...$this->client()->definitions()] as $definition) {
            self::assertNotSame('', $definition->label, $definition->name);
            self::assertNotSame([], $definition->example, $definition->name);
        }
    }

    /**
     * @param list<VariableDefinition> $definitions
     *
     * @return list<string>
     */
    private function names(array $definitions): array
    {
        return array_map(static fn (VariableDefinition $definition): string => $definition->name, $definitions);
    }

    /**
     * @param list<VariableDefinition> $definitions
     *
     * @return array<string, string|list<array<string, string>>>
     */
    private function examples(array $definitions): array
    {
        return array_combine($this->names($definitions), array_map(static fn (VariableDefinition $definition): string|array => $definition->example, $definitions));
    }

    private function firm(): FirmVariables
    {
        $container = self::getContainer();

        return new FirmVariables($container->get(InstanceFirm::class), $container->get(FirmBankAccounts::class), $container->get(FirmImages::class), $container->get('translator'));
    }

    private function client(): ClientVariables
    {
        return new ClientVariables(self::getContainer()->get(PartyDirectory::class), self::getContainer()->get('translator'));
    }
}
