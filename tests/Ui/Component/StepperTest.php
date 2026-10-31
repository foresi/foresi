<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class StepperTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const array STEPS = ['Upload', 'Columns', 'Check', 'Import', 'Summary'];

    public function testMarksTheStepsBeforeAsDoneAndTheCurrentOne(): void
    {
        $crawler = $this->renderTwigComponent('Ui:Stepper', ['steps' => self::STEPS, 'current' => 2, 'label' => 'Import steps'])->crawler();

        self::assertSame('Import steps', $crawler->filter('nav')->attr('aria-label'));
        self::assertCount(5, $crawler->filter('li'));
        $current = $crawler->filter('li[aria-current="step"]');
        self::assertCount(1, $current);
        self::assertStringContainsString('Check', $current->text());
        self::assertCount(2, $crawler->filter('li .bg-green.text-white'));
        // Done steps say so to screen readers; the narrow layout hides only the labels of the other steps.
        self::assertCount(2, $crawler->filter('li span.sr-only'));
        self::assertCount(4, $crawler->filter('li .max-sm\:sr-only'));
        self::assertCount(4, $crawler->filter('li > span[aria-hidden="true"].h-px'));
    }

    public function testTheFirstStepHasNothingDone(): void
    {
        $crawler = $this->renderTwigComponent('Ui:Stepper', ['steps' => self::STEPS, 'current' => 0, 'label' => 'Steps'])->crawler();

        self::assertCount(0, $crawler->filter('li .bg-green.text-white'));
        self::assertStringContainsString('Upload', $crawler->filter('li[aria-current="step"]')->text());
    }

    public function testRejectsACurrentStepOutsideTheSteps(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:Stepper', ['steps' => self::STEPS, 'current' => 5, 'label' => 'Steps']);
    }

    public function testRejectsASingleStepAndAMissingLabel(): void
    {
        foreach ([['steps' => ['Only'], 'label' => 'Steps'], ['steps' => self::STEPS, 'label' => ''], ['steps' => ['A', ''], 'label' => 'Steps']] as $props) {
            try {
                $this->renderTwigComponent('Ui:Stepper', $props);
                self::fail('The stepper should refuse these props.');
            } catch (\Throwable $error) {
                self::assertStringNotContainsString('should refuse', $error->getMessage());
            }
        }
    }
}
