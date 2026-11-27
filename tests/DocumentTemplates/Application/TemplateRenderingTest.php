<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Application;

use Foresi\DocumentTemplates\Application\DocumentsRefused;
use Foresi\DocumentTemplates\Application\TemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Review focus 3: a template that fails is refused with what failed, never a 500.
 */
final class TemplateRenderingTest extends TestCase
{
    private const array VALUES = [
        'firm' => ['name' => 'Example Firm s.r.o.', 'address_lines' => ['Example Street 1', '100 00 Example City']],
        'client' => ['name' => 'Žluťoučký kůň a.s.', 'persons' => [['name' => 'Jana Example', 'email' => 'jana@example.com'], ['name' => 'Petr Example', 'email' => null]]],
    ];

    #[Test]
    public function rendersFirmAndClientValues(): void
    {
        $html = new TemplateRenderer()->render(
            '<p>{{ firm.name }} and {{ client.name|upper }}</p>{% for line in firm.address_lines %}<br>{{ line }}{% endfor %}'
            .'{% for person in client.persons %}<li>{{ person.name }} {{ person.email|default("–") }}</li>{% endfor %}{% set count = client.persons|length %}<p>{{ count }}</p>',
            self::VALUES,
        );

        self::assertSame('<p>Example Firm s.r.o. and ŽLUŤOUČKÝ KŮŇ A.S.</p><br>Example Street 1<br>100 00 Example City<li>Jana Example jana@example.com</li><li>Petr Example –</li><p>2</p>', $html);
    }

    #[Test]
    public function missingVariableRefusesWithNames(): void
    {
        try {
            new TemplateRenderer()->render('<p>{{ client.name }}: {{ deal.title }}, {{ deal.value }}, {{ client.vat_id }}</p>', self::VALUES);
            self::fail('A template with missing variables was rendered.');
        } catch (DocumentsRefused $refused) {
            self::assertSame('template_variables_missing', $refused->reason());
            self::assertSame(['missing' => ['deal.title', 'deal.value', 'client.vat_id']], $refused->details());
            self::assertStringContainsString('deal.title', $refused->getMessage());
        }
    }

    #[Test]
    public function aVariableCheckedWithDefinedOrGivenADefaultIsNotMissing(): void
    {
        $html = new TemplateRenderer()->render('{% if deal is defined %}{{ deal.title }}{% endif %}[{{ client.vat_id|default("none") }}]', self::VALUES);

        self::assertSame('[none]', $html);
    }

    #[Test]
    public function loopAndSetVariablesAreNotMissing(): void
    {
        $html = new TemplateRenderer()->render('{% set who = client.name %}{% for i, p in client.persons %}{{ i }}{{ p.name }}{% endfor %}{{ who }}', self::VALUES);

        self::assertSame('0Jana Example1Petr ExampleŽluťoučký kůň a.s.', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blocked(): iterable
    {
        yield 'self' => ['{{ _self }}', '_self'];
        yield 'context' => ['{{ _context|length }}', '_context'];
        yield 'include tag' => ['{% include "other.html" %}', 'include'];
        yield 'include function' => ['{{ include("other.html") }}', 'include'];
        yield 'method call' => ['{{ client.name() }}', 'method call'];
        yield 'raw filter' => ['{{ client.name|raw }}', 'raw'];
        yield 'macro' => ['{% macro x() %}{% endmacro %}', 'macro'];
        yield 'source function' => ['{{ source("x") }}', 'source'];
        yield 'syntax error' => ['{{ client.name ', 'syntax'];
    }

    #[Test]
    #[DataProvider('blocked')]
    public function sandboxBlocksIncludeSelfAndMethodCalls(string $body, string $construct): void
    {
        try {
            new TemplateRenderer()->render($body, self::VALUES);
            self::fail('A template outside the sandbox was rendered.');
        } catch (DocumentsRefused $refused) {
            self::assertSame('template_not_allowed', $refused->reason());
            self::assertSame(['construct' => $construct], $refused->details());
        }
    }

    #[Test]
    public function outputIsHtmlEscaped(): void
    {
        $html = new TemplateRenderer()->render('<p>{{ client.name }}</p>{{ client.note|nl2br }}', ['client' => ['name' => '<script>alert(1)</script> & "x"', 'note' => "a<b>\nc"]]);

        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;</p>a&lt;b&gt;<br />'."\n".'c', $html);
    }

    #[Test]
    public function valuesMustBeScalarsAndArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TemplateRenderer()->render('{{ client.name }}', ['client' => ['name' => new \ArrayObject()]]);
    }

    #[Test]
    public function theVariablesATemplateUsesAreListed(): void
    {
        self::assertSame(
            ['client.name', 'client.persons', 'deal.title', 'firm.name'],
            new TemplateRenderer()->variables('{{ firm.name }}{% for p in client.persons %}{{ p.name }}{% endfor %}{{ client.name }}{{ deal.title }}{{ firm.name }}'),
        );
    }

    #[Test]
    public function aGuardedVariableLeavesTheOthersMissing(): void
    {
        try {
            new TemplateRenderer()->render('{% if deal is defined %}{{ deal.title }}{% endif %}{{ dealer.name }}{{ client.name }}', self::VALUES);
            self::fail('A template with a missing variable was rendered.');
        } catch (DocumentsRefused $refused) {
            self::assertSame(['missing' => ['dealer.name']], $refused->details());
        }
    }

    #[Test]
    public function aValueThatFailsWhileRenderingIsRefused(): void
    {
        try {
            new TemplateRenderer()->render('{% for person in client.persons %}{{ person.phone }}{% endfor %}', self::VALUES);
            self::fail('A template that reads a key its values lack was rendered.');
        } catch (DocumentsRefused $refused) {
            self::assertSame('template_failed', $refused->reason());
            self::assertSame('body', $refused->field());
        }
    }

    #[Test]
    public function aSyntaxErrorIsRefusedWhenChecked(): void
    {
        try {
            new TemplateRenderer()->check('<p>{{ client.name </p>');
            self::fail('A template with a syntax error passed the check.');
        } catch (DocumentsRefused $refused) {
            self::assertSame('template_not_allowed', $refused->reason());
            self::assertSame(['construct' => 'syntax'], $refused->details());
        }
    }

    #[Test]
    public function theVariablesOfLoopsKeysAndExpressionsAreListed(): void
    {
        $renderer = new TemplateRenderer();

        self::assertSame([], $renderer->variables('<p>No variables</p>'));
        self::assertSame(['client.persons'], $renderer->variables('{% for p in client.persons %}{{ loop.index }}{% endfor %}'));
        self::assertSame(['client.name'], $renderer->variables('{% if client %}{{ client.name }}{% endif %}'));
        self::assertSame(['client', 'deal.key'], $renderer->variables('{{ client[deal.key] }}'));
        self::assertSame(['client.name'], $renderer->variables('{{ ("a" ~ client.name).length }}'));
        self::assertSame(['client.persons'], $renderer->variables('{{ client.persons[0] }}'));
    }
}
