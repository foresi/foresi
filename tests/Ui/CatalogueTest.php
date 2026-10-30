<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

final class CatalogueTest extends WebTestCase
{
    /**
     * Every Twig component of src/Ui/Component, by its kebab-case name.
     *
     * @return list<string>
     */
    private static function componentNames(): array
    {
        $names = [];
        $files = glob(\dirname(__DIR__, 2).'/src/Ui/Component/*.php');
        self::assertIsArray($files);
        foreach ($files as $file) {
            $class = 'App\\Ui\\Component\\'.basename($file, '.php');
            self::assertTrue(class_exists($class), sprintf('%s should be loadable.', $class));
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || [] === $reflection->getAttributes(AsTwigComponent::class)) {
                continue;
            }
            $names[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $reflection->getShortName()));
        }

        return $names;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function components(): iterable
    {
        foreach (self::componentNames() as $name) {
            yield $name => [$name];
        }
    }

    public function testEveryComponentHasASection(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_catalogue');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(20, count(self::componentNames()));
        foreach (self::componentNames() as $name) {
            self::assertCount(1, $crawler->filter('section#component-'.$name), sprintf('The catalogue has no section for the component "%s".', $name));
        }
    }

    #[DataProvider('components')]
    public function testEverySectionRendersOnItsOwnInTheMobileFrame(string $name): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_catalogue', ['frame' => 'mobile', 'section' => $name]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('section[id^="component-"]'));
        self::assertCount(1, $crawler->filter('section#component-'.$name));
    }

    public function testEverySectionOffersAMobilePreview(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_catalogue');

        self::assertCount(count(self::componentNames()), $crawler->filter('iframe[data-mobile-preview][width="390"]'));
        self::assertStringContainsString('frame=mobile', (string) $crawler->filter('iframe[data-mobile-preview]')->first()->attr('src'));
    }

    public function testAnUnknownSectionIsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_catalogue', ['frame' => 'mobile', 'section' => 'nothing']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLanguageToggleSwitchesTheCatalogue(): void
    {
        $client = self::createClient();

        $cs = $client->request('GET', '/_catalogue', ['locale' => 'cs']);
        self::assertSame('cs', $cs->filter('html')->attr('lang'));
        $csHtml = (string) $client->getResponse()->getContent();

        $en = $client->request('GET', '/_catalogue', ['locale' => 'en']);
        self::assertSame('en', $en->filter('html')->attr('lang'));
        $enHtml = (string) $client->getResponse()->getContent();

        self::assertNotSame($csHtml, $enHtml);
        self::assertStringContainsString('Nothing here yet', $enHtml);
        self::assertStringContainsString('Zatím tu nic není', $csHtml);
        self::assertSame('/_catalogue?locale=en', $cs->filter('a[hreflang="en"]')->attr('href'));
    }

    public function testAnUnsupportedLocaleFallsBackToCzech(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_catalogue', ['locale' => 'xx']);

        self::assertResponseIsSuccessful();
        self::assertSame('cs', $crawler->filter('html')->attr('lang'));
    }

    public function testTheCatalogueShowsRealisticLongAndDiacriticData(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_catalogue');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Example Company', $html);
        self::assertStringContainsString('Příliš žluťoučký kůň', $html);
    }

    public function testTheCatalogueDoesNotExistInProduction(): void
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertNull($router->getRouteCollection()->get('catalogue'));
            try {
                $router->match('/_catalogue');
                self::fail('The catalogue route must not exist in production.');
            } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
                $this->addToAssertionCount(1);
            }
            $this->expectException(RouteNotFoundException::class);
            $router->generate('catalogue');
        } finally {
            $kernel->shutdown();
        }
    }
}
