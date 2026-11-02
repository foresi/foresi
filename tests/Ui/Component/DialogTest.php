<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class DialogTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props, string $body = '<p>Body</p>', string $footer = '<button type="button">Cancel</button>'): Crawler
    {
        $html = (string) $this->renderTwigComponent('Ui:Dialog', ['id' => 'test-dialog', 'title' => 'Send invoice 20260001'] + $props, $body, ['footer' => $footer]);

        return new Crawler($html);
    }

    public function testItIsANativeDialogLabelledByItsVisibleTitle(): void
    {
        $dialog = $this->render([])->filter('dialog#test-dialog');

        self::assertCount(1, $dialog);
        self::assertSame('dialog', $dialog->attr('data-controller'));
        self::assertSame('test-dialog-title', $dialog->attr('aria-labelledby'));
        self::assertSame('Send invoice 20260001', $dialog->filter('h2#test-dialog-title')->text());
        // A closed dialog is not shown: the open state alone lays it out.
        self::assertNull($dialog->attr('open'));
        self::assertStringContainsString('open:flex', (string) $dialog->attr('class'));
        self::assertStringNotContainsString(' flex ', ' '.$dialog->attr('class').' ');
    }

    public function testTheHeaderAlwaysHasACloseButtonAndTheBodyScrollsAlone(): void
    {
        $crawler = $this->render([]);

        $close = $crawler->filter('header button[aria-label="Zavřít"]');
        self::assertCount(1, $close);
        self::assertSame('dialog#dismiss', $close->attr('data-action'));
        $body = $crawler->filter('[data-dialog-target="body"]');
        self::assertStringContainsString('overflow-y-auto', (string) $body->attr('class'));
        self::assertSame('Body', $body->text());
        self::assertSame('Cancel', $crawler->filter('footer [data-dialog-target="actions"]')->text());
    }

    public function testADescriptionDescribesTheDialog(): void
    {
        $dialog = $this->render(['description' => 'One line about the task.'])->filter('dialog');

        self::assertSame('test-dialog-description', $dialog->attr('aria-describedby'));
        self::assertSame('One line about the task.', $dialog->filter('#test-dialog-description')->text());
        self::assertNull($this->render([])->filter('dialog')->attr('aria-describedby'));
    }

    public function testSizesSetTheWidth(): void
    {
        self::assertStringContainsString('max-w-[400px]', (string) $this->render(['size' => 'sm'])->filter('dialog')->attr('class'));
        self::assertStringContainsString('max-w-[560px]', (string) $this->render([])->filter('dialog')->attr('class'));
        self::assertStringContainsString('max-w-[720px]', (string) $this->render(['size' => 'lg'])->filter('dialog')->attr('class'));
    }

    public function testUnknownSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Dialog', ['id' => 'x', 'title' => 'x', 'size' => 'xl']);
    }

    public function testADialogWithFieldsIsFullScreenOnAPhoneAndIgnoresTheBackdrop(): void
    {
        $withFields = $this->render(['fields' => true])->filter('dialog');
        $without = $this->render([])->filter('dialog');

        self::assertStringContainsString('max-sm:h-dvh', (string) $withFields->attr('class'));
        self::assertSame('true', $withFields->attr('data-dialog-fields-value'));
        self::assertStringNotContainsString('max-sm:h-dvh', (string) $without->attr('class'));
        self::assertSame('false', $without->attr('data-dialog-fields-value'));
    }

    public function testAConfirmationIsAnAlertDialog(): void
    {
        $dialog = $this->render(['variant' => 'confirm', 'size' => 'sm'])->filter('dialog');

        self::assertSame('alertdialog', $dialog->attr('role'));
        // The question is the name, the consequence its description (APG alert dialog).
        self::assertSame('test-dialog-text', $dialog->attr('aria-describedby'));
        self::assertSame('true', $dialog->attr('data-dialog-confirm-value'));
        self::assertStringContainsString('dialog:confirm@document->dialog#ask', (string) $dialog->attr('data-action'));
        self::assertNull($this->render([])->filter('dialog')->attr('role'));
    }

    public function testChangesAskInsideTheDialogBeforeTheyAreDiscarded(): void
    {
        $discard = $this->render(['fields' => true])->filter('footer [data-dialog-target="discard"]');

        self::assertNotNull($discard->attr('hidden'));
        self::assertSame('Zahodit neuložené změny?', $discard->filter('#test-dialog-discard')->text());
        $keep = $discard->filter('button[data-action="dialog#keepEditing"]');
        self::assertSame('Pokračovat v úpravách', $keep->text());
        self::assertSame('test-dialog-discard', $keep->attr('aria-describedby'));
        self::assertSame('Zahodit', $discard->filter('button[data-action="dialog#discard"]')->text());
    }

    public function testItClosesOnTurboCacheAndRenderAndKeepsItsOpenStateFromMorphs(): void
    {
        $actions = (string) $this->render([])->filter('dialog')->attr('data-action');

        foreach (['cancel->dialog#cancelled', 'close->dialog#closed', 'turbo:before-cache@document->dialog#reset', 'turbo:before-render@document->dialog#reset', 'turbo:before-morph-attribute->dialog#keepOpen', 'submit->dialog#submit', 'input->dialog#changed'] as $action) {
            self::assertStringContainsString($action, $actions);
        }
    }

    public function testTheRemoteHostIsEmptyAndFollowsDialogLinks(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Dialog', ['id' => 'dialog', 'remote' => true]);
        $dialog = new Crawler($html)->filter('dialog#dialog');

        self::assertSame('true', $dialog->attr('data-dialog-remote-value'));
        self::assertStringContainsString('click@document->dialog#follow', (string) $dialog->attr('data-action'));
        self::assertStringContainsString('popstate@window->dialog#popped', (string) $dialog->attr('data-action'));
        self::assertCount(0, $dialog->filter('header'));
    }

    public function testABareDialogHasNoChromeAndIsNamedByItsLabel(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Dialog', ['id' => 'palette', 'variant' => 'bare', 'label' => 'Command palette', 'dismissable' => true], '<input type="text">');
        $dialog = new Crawler($html)->filter('dialog#palette');

        self::assertSame('Command palette', $dialog->attr('aria-label'));
        self::assertNull($dialog->attr('aria-labelledby'));
        self::assertSame('true', $dialog->attr('data-dialog-dismissable-value'));
        self::assertCount(0, $dialog->filter('header'));
        self::assertCount(0, $dialog->filter('footer'));
    }

    public function testATitleIsRequiredUnlessBareOrRemote(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Dialog', ['id' => 'x']);
    }
}
