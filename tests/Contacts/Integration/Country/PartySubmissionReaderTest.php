<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Integration\Country;

use Foresi\Contacts\Infrastructure\Form\PartySubmissionReader;
use Foresi\Country\CzechRepublic\Party\CzechRegistrationNumberValidator;
use Foresi\Party\Application\PartyRules;
use Foresi\Party\Application\RegistrationNumberFactory;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

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
            'kind' => 'contacts.error.kind',
            'displayName' => 'party.error.required',
            'street' => 'party.error.required',
            'city' => 'party.error.too_long',
            'postalCode' => 'party.error.required',
            'phone' => 'party.error.too_long',
            'countryCode' => 'party.error.country',
            'email' => 'contacts.error.email',
            'registrationNumber' => 'party.error.registration_number',
            'vatId' => 'party.error.vat_id',
        ], $submission->errors);
        self::assertNotInstanceOf(\Foresi\Party\Application\PartyInput::class, $submission->input);
    }

    public function testACompanyNeedsAnIdentifierButAPersonDoesNot(): void
    {
        self::assertSame(['registrationNumber' => 'contacts.error.company_identifier'], $this->read($this->post(['registrationNumber' => '', 'vatId' => '']))->errors);
        self::assertTrue($this->read($this->post(['kind' => 'person', 'registrationNumber' => '', 'vatId' => '']))->valid());
    }

    public function testTheKindOfAnExistingPartyCannotBeChanged(): void
    {
        $submission = $this->read($this->post(['kind' => 'person']), PartyKind::Company);

        self::assertSame('company', $submission->values['kind']);
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
    private function read(array $post, ?PartyKind $fixed = null): \Foresi\Contacts\Infrastructure\Form\PartySubmission
    {
        $reader = new PartySubmissionReader(new PartyRules(new RegistrationNumberFactory([new CzechRegistrationNumberValidator()])));

        return $reader->read(new Request(request: $post), $fixed);
    }
}
