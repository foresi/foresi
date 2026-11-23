<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Domain;

use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class MarginRefusedTest extends TestCase
{
    public function testAnAllocationRefusesThroughTheSharedContractAboutItsShares(): void
    {
        try {
            new Allocation(AllocationKind::Whole, []);
            self::fail('A whole allocation without a recipient is refused.');
        } catch (MarginRefused $refused) {
            self::assertContains(Refused::class, class_implements($refused));
            self::assertSame(['whole_needs_one', RefusalKind::Invalid, 'shares'], [$refused->reason(), $refused->kind(), $refused->field()]);
        }
    }

    public function testTheRefusalsOfTheUseCasesHaveTheirKinds(): void
    {
        self::assertSame(RefusalKind::NotFound, MarginRefused::notFound(MarginRefused::UNKNOWN_LINE, 'No such line.')->kind());
        self::assertSame(RefusalKind::Forbidden, MarginRefused::forbidden()->kind());
        self::assertSame('recipient', MarginRefused::invalid(MarginRefused::UNKNOWN_CONTACT, 'recipient', 'No such contact.')->field());
    }

    public function testEveryReasonIsTranslatedInBothLanguages(): void
    {
        $reasons = array_values(array_filter((new \ReflectionClass(MarginRefused::class))->getConstants(), is_string(...)));
        foreach (['en', 'cs'] as $language) {
            $translations = Yaml::parseFile(\dirname(__DIR__, 3).'/src/Margin/translations/messages+intl-icu.'.$language.'.yaml');
            self::assertIsArray($translations);
            self::assertIsArray($translations['margin']);
            self::assertIsArray($translations['margin']['refused']);
            foreach ($reasons as $reason) {
                self::assertArrayHasKey($reason, $translations['margin']['refused'], $language.' '.$reason);
            }
        }
    }
}
