<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Markup;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\Test\DefinedTest;
use Twig\Node\Expression\Variable\AssignContextVariable;
use Twig\Node\Node;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityPolicy;
use Twig\Source;
use Twig\Template;

/**
 * Renders the body of a document template in a Twig environment of its own,
 * always sandboxed: the tags if, for and set, a short list of filters, no
 * functions, no methods and no properties of objects. Values are scalars and
 * nested arrays only, and every output is HTML-escaped, except the <img> of an
 * image variable (DocumentVariables::printable()).
 *
 * A template that uses a variable with no value, or anything outside the
 * sandbox, is refused with what it used: never an error page.
 */
final readonly class TemplateRenderer
{
    private const array TAGS = ['if', 'for', 'set'];
    private const array FILTERS = ['escape', 'e', 'upper', 'lower', 'date', 'number_format', 'nl2br', 'default', 'join', 'length'];

    private Environment $twig;

    public function __construct()
    {
        // Twig escapes HTML and keeps no cache by default.
        $this->twig = new Environment(new ArrayLoader(), ['strict_variables' => true]);
        $this->twig->addExtension(new SandboxExtension(new SecurityPolicy(self::TAGS, self::FILTERS, [], [], []), true));
    }

    /**
     * @param array<string, mixed> $values by prefix, such as ['client' => ['name' => ...]]
     *
     * @return string the HTML
     *
     * @throws DocumentsRefused template_not_allowed, template_variables_missing or template_failed
     */
    public function render(string $body, array $values): string
    {
        self::checkValues($values);
        $uses = $this->analyse($body);
        $missing = array_values(array_filter($uses['paths'], static fn (string $path): bool => !self::isOptional($path, $uses['optional']) && !self::has($values, $path)));
        if ([] !== $missing) {
            throw DocumentsRefused::missingVariables($missing);
        }

        // The sandbox was checked by analyse(), and the values hold no objects.
        try {
            return $this->twig->createTemplate($body)->render($values);
        } catch (TwigError $failed) {
            throw DocumentsRefused::invalid('template_failed', 'body', \sprintf('The template could not be rendered: %s', $failed->getRawMessage()));
        }
    }

    /**
     * Checks a template without values: that it parses and stays in the sandbox.
     *
     * @throws DocumentsRefused template_not_allowed
     */
    public function check(string $body): void
    {
        $this->analyse($body);
    }

    /**
     * The variables a template reads, such as "client.name", in name order.
     *
     * @return list<string>
     *
     * @throws DocumentsRefused template_not_allowed
     */
    public function variables(string $body): array
    {
        $paths = $this->analyse($body)['paths'];
        sort($paths);

        return $paths;
    }

    /**
     * Parses the template, refuses what the sandbox would refuse, and lists the
     * variable paths it reads.
     *
     * @return array{paths: array<int, string>, optional: list<string>}
     *
     * @throws DocumentsRefused template_not_allowed
     */
    private function analyse(string $body): array
    {
        try {
            $module = $this->twig->parse($this->twig->tokenize(new Source($body, 'template')));
        } catch (SyntaxError $invalid) {
            throw $this->invalid($invalid);
        }
        try {
            // The sandbox policy is checked when the template starts to display.
            $this->twig->createTemplate($body)->render([]);
        } catch (SecurityError $blocked) {
            throw $this->blocked($blocked);
        } catch (TwigError) {
            // Without values a template may fail to render; only what it uses matters here.
        }

        $found = ['paths' => [], 'optional' => [], 'bound' => ['loop']];
        $this->walk($module, $found, false);
        $paths = array_unique(array_filter($found['paths'], static fn (string $path): bool => !\in_array(explode('.', $path)[0], $found['bound'], true)));
        // A path also read deeper is covered by the deeper one.
        $paths = array_filter($paths, static fn (string $path): bool => array_all($paths, fn (string $other): bool => !str_starts_with($other, $path.'.')));

        return ['paths' => $paths, 'optional' => $found['optional']];
    }

    /**
     * @param array{paths: list<string>, optional: list<string>, bound: list<string>} $found
     */
    private function walk(Node $node, array &$found, bool $defined): void
    {
        if ($node instanceof AssignContextVariable) {
            $found['bound'][] = $this->name($node);

            return;
        }
        $defined = $defined || $node instanceof DefinedTest;
        $path = $this->path($node);
        if (null !== $path) {
            $found[$defined ? 'optional' : 'paths'][] = $path;
        }
        foreach ($node as $name => $child) {
            // Of a path only the arguments of its attribute may hold paths of their own.
            if (null === $path || 'node' !== $name) {
                $this->walk($child, $found, $defined);
            }
        }
    }

    /**
     * The name of a variable node; Twig keeps it as a string attribute.
     */
    private function name(Node $node): string
    {
        $name = $node->getAttribute('name');

        return \is_string($name) ? $name : '';
    }

    private function invalid(SyntaxError $invalid): DocumentsRefused
    {
        return DocumentsRefused::blockedTemplate('syntax', \sprintf('The template is not valid on line %d: %s', $invalid->getTemplateLine(), $invalid->getRawMessage()));
    }

    /**
     * "client.name" for {{ client.name }}; null when the expression is not a
     * plain path of names, such as client[key].
     *
     * @throws DocumentsRefused template_not_allowed for a method call or a special variable
     */
    private function path(Node $node): ?string
    {
        if ($node instanceof NameExpression) {
            $name = $this->name($node);
            if (str_starts_with($name, '_')) {
                throw DocumentsRefused::blockedTemplate($name, \sprintf('Templates cannot use %s; use the variables listed beside the editor.', $name));
            }

            return $name;
        }
        if (!$node instanceof GetAttrExpression) {
            return null;
        }
        if (Template::METHOD_CALL === $node->getAttribute('type')) {
            throw DocumentsRefused::blockedTemplate('method call', 'Templates cannot call methods; use the variables listed beside the editor, without brackets.');
        }
        $attribute = $node->getNode('attribute');
        $parent = $this->path($node->getNode('node'));
        if (null === $parent || !$attribute instanceof ConstantExpression || !\is_string($attribute->getAttribute('value'))) {
            return $parent;
        }

        return $parent.'.'.$attribute->getAttribute('value');
    }

    private function blocked(SecurityError $blocked): DocumentsRefused
    {
        // Methods and properties are never reached: the values hold no objects.
        $construct = match (true) {
            $blocked instanceof SecurityNotAllowedTagError => $blocked->getTagName(),
            $blocked instanceof SecurityNotAllowedFilterError => $blocked->getFilterName(),
            default => $blocked instanceof SecurityNotAllowedFunctionError ? $blocked->getFunctionName() : 'sandbox',
        };

        return DocumentsRefused::blockedTemplate($construct, \sprintf('Templates cannot use "%s". They may use the tags if, for and set, and the filters %s.', $construct, implode(', ', self::FILTERS)));
    }

    /**
     * @param list<string> $optional
     */
    private static function isOptional(string $path, array $optional): bool
    {
        return array_any($optional, fn (string $guarded): bool => $path === $guarded || str_starts_with($path, $guarded.'.'));
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function has(array $values, string $path): bool
    {
        $value = $values;
        foreach (explode('.', $path) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }

        return true;
    }

    /**
     * @param array<mixed> $values
     */
    private static function checkValues(array $values): void
    {
        foreach ($values as $value) {
            if (\is_array($value)) {
                self::checkValues($value);
            } elseif (null !== $value && !\is_scalar($value) && !$value instanceof Markup) {
                throw new \InvalidArgumentException('Template values are scalars, arrays and the markup of an image only.');
            }
        }
    }
}
