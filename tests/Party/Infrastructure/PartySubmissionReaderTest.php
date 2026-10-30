<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Country\CzechRepublic\Party\CzechRegistrationNumberValidator;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Infrastructure\Form\CountryOptions;
use App\Party\Infrastructure\Form\PartySubmissionReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validation;

final class PartySubmissionReaderTest extends TestCase
{
    public function testAValidSubmissionIsParsedIntoDomainValues(): void
    {
        $submission = $this->read($this->post(['registrationNumber' => '1234 5687', 'vatId' => 'cz 12345687', 'roles' => ['customer', 'bogus', 'supplier', 'customer']]));

        self::assertTrue($submission->valid());
        $input = $submission->input ?? self::fail();
        self::assertSame(PartyKind::Company, $input->kind);
        self::assertSame('12345687', $input->registrationNumber?->value);
        self::assertSame('CZ12345687', $input->vatId?->toString());
        self::assertSame([PartyRole::Customer, PartyRole::Supplier], $input->roles);
        self::assertSame('Example City', $input->billingAddress->city);
    }

    public function testEveryProblemIsReportedAtOnceWithATranslationKey(): void
    {
        $submission = $this->read($this->post([
            'kind' => 'nonsense', 'displayName' => '  ', 'registrationNumber' => '12345670', 'vatId' => 'CZ1', 'email' => 'not an address',
            'street' => '', 'city' => str_repeat('x', 129), 'postalCode' => '', 'countryCode' => 'ZZ', 'phone' => str_repeat('1', 65),
        ]));

        self::assertFalse($submission->valid());
        self::assertSame([
            'kind' => 'party.error.kind',
            'displayName' => 'party.error.required',
            'street' => 'party.error.required',
            'city' => 'party.error.too_long',
            'postalCode' => 'party.error.required',
            'phone' => 'party.error.too_long',
            'countryCode' => 'party.error.country',
            'email' => 'party.error.email',
            'registrationNumber' => 'party.error.registration_number',
            'vatId' => 'party.error.vat_id',
        ], $submission->errors);
        self::assertNotInstanceOf(\App\Party\Application\PartyInput::class, $submission->input);
    }

    public function testACompanyNeedsAnIdentifierButAPersonDoesNot(): void
    {
        self::assertSame(['registrationNumber' => 'party.error.company_identifier'], $this->read($this->post(['registrationNumber' => '', 'vatId' => '']))->errors);
        self::assertTrue($this->read($this->post(['kind' => 'person', 'registrationNumber' => '', 'vatId' => '']))->valid());
    }

    public function testTheKindOfAnExistingPartyCannotBeChanged(): void
    {
        $submission = $this->read($this->post(['kind' => 'person']), PartyKind::Company);

        self::assertSame('company', $submission->values['kind']);
    }

    public function testCountryNamesFollowTheLocaleAndTheFirstTwoStayOnTop(): void
    {
        $english = CountryOptions::options('en');
        $czech = CountryOptions::options('cs');

        self::assertSame(['CZ', 'SK'], \array_slice(array_keys($english), 0, 2));
        self::assertSame('Czechia', $english['CZ']);
        self::assertSame('Česko', $czech['CZ']);
        self::assertCount(\count(CountryOptions::CODES), $english);
        self::assertTrue(CountryOptions::supports('DE'));
        self::assertFalse(CountryOptions::supports('ZZ'));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function post(array $overrides): array
    {
        return $overrides + [
            'kind' => 'company', 'displayName' => 'Example Company s.r.o.', 'registrationCountry' => 'CZ', 'registrationNumber' => '12345687', 'vatId' => '',
            'email' => 'info@example.com', 'phone' => '', 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '100 00', 'countryCode' => 'CZ', 'privateNote' => '',
        ];
    }

    /**
     * @param array<string, mixed> $post
     */
    private function read(array $post, ?PartyKind $fixed = null): \App\Party\Infrastructure\Form\PartySubmission
    {
        $reader = new PartySubmissionReader(new RegistrationNumberFactory([new CzechRegistrationNumberValidator()]), Validation::createValidator());

        return $reader->read(new Request(request: $post), $fixed);
    }
}
