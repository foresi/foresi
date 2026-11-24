<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Unit\Reading;

use Foresi\Purchasing\Contract\Reading\DocumentReader;
use Foresi\Purchasing\PurchasingBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class DocumentReaderTagTest extends TestCase
{
    /**
     * Autoconfiguration is container-wide, so a reader in Country or an optional
     * module is tagged without Purchasing's own service file naming it.
     */
    public function testAnyDocumentReaderIsAutoconfiguredWithTheTag(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $path = new PurchasingBundle()->getPath().'/config';
        new PhpFileLoader($builder, new FileLocator($path))->load('services.php');

        $tags = $builder->getAutoconfiguredInstanceof()[DocumentReader::class]->getTags();

        self::assertArrayHasKey('foresi.purchasing.document_reader', $tags);
    }
}
