<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Catalogue;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Module\ModuleGraph;
use App\Shared\Domain\Module\ModuleId;
use App\Shared\Infrastructure\Module\InstalledModules;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The contract events of the installed modules: every class in a module's
 * Contract/Event directory that carries #[DomainEvent]. Only the directories of
 * installed modules are read, and only by reflection of classes the autoloader
 * knows. A class that breaks a rule is left out of the catalogue and reported by
 * problems(), which app:events --check turns into a failing build.
 *
 * Facts are the public promoted constructor properties: string, int, bool, null,
 * a backed enum of the module's Contract namespace, or an array documented as
 * list<string>.
 */
final class EventCatalogue
{
    private const string NAME = '/^[a-z][a-z0-9-]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /**
     * @var list<array{ModuleId, string, string}>|null the module, its namespace and its directory
     */
    private ?array $sources = null;

    /**
     * @var array{types: array<string, EventType>, problems: list<string>}|null
     */
    private ?array $scanned = null;

    public function __construct(
        private readonly InstalledModules $modules,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * A catalogue of the given modules instead of the installed ones.
     *
     * @param list<array{ModuleId, string, string}> $sources the module, its namespace ("App\Invoicing\") and its directory
     */
    public static function scan(array $sources): self
    {
        $catalogue = new self(new InstalledModules(''), '');
        $catalogue->sources = $sources;

        return $catalogue;
    }

    /**
     * A catalogue of the modules of a graph, read from the src/ directory of a project.
     */
    public static function forGraph(ModuleGraph $graph, string $projectDir): self
    {
        $catalogue = new self(new InstalledModules($projectDir), $projectDir);
        $catalogue->sources = $catalogue->sourcesOf($graph);

        return $catalogue;
    }

    /**
     * @return list<EventType> sorted by name, then version
     */
    public function all(): array
    {
        $types = array_values($this->read()['types']);
        usort($types, static fn (EventType $a, EventType $b): int => [$a->name, $a->version] <=> [$b->name, $b->version]);

        return $types;
    }

    public function byName(string $name, int $version): EventType
    {
        return $this->read()['types'][$this->key($name, $version)] ?? throw UnknownEventType::named($name, $version);
    }

    public function byClass(string $class): EventType
    {
        foreach ($this->read()['types'] as $type) {
            if ($type->class === $class) {
                return $type;
            }
        }

        throw UnknownEventType::ofClass($class);
    }

    public function has(string $name): bool
    {
        foreach ($this->read()['types'] as $type) {
            if ($type->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The event names that match the patterns, each once and sorted. A pattern
     * is an event name or "module.*" for every event of a module.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    public function matching(array $patterns): array
    {
        $names = [];
        foreach ($this->read()['types'] as $type) {
            foreach ($patterns as $pattern) {
                if ($pattern === $type->name || (str_ends_with($pattern, '.*') && substr($pattern, 0, -1) === $type->module->value.'.')) {
                    $names[$type->name] = true;
                    break;
                }
            }
        }
        $names = array_keys($names);
        sort($names, \SORT_STRING);

        return $names;
    }

    /**
     * What is wrong with the contract events, as sorted codes such as
     * "prefix:invoicing:party.invoice.issued". Empty when all is well.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->read()['problems'];
    }

    /**
     * @return array{types: array<string, EventType>, problems: list<string>}
     */
    private function read(): array
    {
        if (null !== $this->scanned) {
            return $this->scanned;
        }

        $types = [];
        $problems = [];
        foreach ($this->sources() as [$module, $namespace, $directory]) {
            $files = glob($directory.'/Contract/Event/*.php');
            $files = false === $files ? [] : $files;
            sort($files, \SORT_STRING);
            foreach ($files as $file) {
                $class = $namespace.'Contract\\Event\\'.basename($file, '.php');
                $this->inspect($module, $namespace, $class, $types, $problems);
            }
        }
        $problems = array_values(array_unique($problems));
        sort($problems, \SORT_STRING);

        return $this->scanned = ['types' => $types, 'problems' => $problems];
    }

    /**
     * @param array<string, EventType> $types
     * @param list<string>             $problems
     */
    private function inspect(ModuleId $module, string $namespace, string $class, array &$types, array &$problems): void
    {
        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            $problems[] = 'class:'.$class;

            return;
        }
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(DomainEvent::class);
        $isPayload = is_a($class, DomainEventPayload::class, true);
        if ([] === $attributes && !$isPayload) {
            return;
        }
        if ([] === $attributes || !$isPayload || !$reflection->isFinal() || !$reflection->isReadOnly() || !$reflection->isInstantiable()) {
            $problems[] = 'shape:'.$class;

            return;
        }

        $event = $attributes[0]->newInstance();
        if (1 !== preg_match(self::NAME, $event->name)) {
            $problems[] = 'name:'.$class;

            return;
        }
        if ($event->version < 1) {
            $problems[] = 'version:'.$class;

            return;
        }
        $broken = false;
        if (!str_starts_with($event->name, $module->value.'.')) {
            $problems[] = 'prefix:'.$module->value.':'.$event->name;
            $broken = true;
        }
        $fields = $this->fields($reflection, $namespace, $problems);
        if ($broken || null === $fields) {
            return;
        }

        $key = $this->key($event->name, $event->version);
        if (isset($types[$key])) {
            $problems[] = 'duplicate:'.$key;

            return;
        }
        /* @var class-string<DomainEventPayload> $class */
        $types[$key] = new EventType($event->name, $event->version, $module, $class, $fields);
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<string>             $problems
     *
     * @return list<string>|null the snake case names of the facts, or null when one of them is not allowed
     */
    private function fields(\ReflectionClass $class, string $namespace, array &$problems): ?array
    {
        $fields = [];
        $allowed = true;
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$parameter->isPromoted()) {
                continue;
            }
            if (!$this->isFact($parameter, $namespace)) {
                $problems[] = 'fact:'.$class->getName().'::'.$parameter->getName();
                $allowed = false;
            }
            $fields[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $parameter->getName()));
        }

        return $allowed ? $fields : null;
    }

    private function isFact(\ReflectionParameter $parameter, string $namespace): bool
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        $name = $type->getName();
        if (\in_array($name, ['string', 'int', 'bool', 'null'], true)) {
            return true;
        }
        if ('array' === $name) {
            $comment = $parameter->getDeclaringFunction()->getDocComment();

            return false !== $comment
                && 1 === preg_match('/@param\s+list<string>\s+\$'.preg_quote($parameter->getName(), '/').'\b/', $comment);
        }
        if (!enum_exists($name)) {
            return false;
        }

        return new \ReflectionEnum($name)->isBacked() && str_starts_with($name, $namespace.'Contract\\');
    }

    /**
     * @return list<array{ModuleId, string, string}>
     */
    private function sources(): array
    {
        if (null !== $this->sources) {
            return $this->sources;
        }

        return $this->sources = $this->sourcesOf($this->modules->graph());
    }

    /**
     * @return list<array{ModuleId, string, string}> each module of the graph with its directory below src/
     */
    private function sourcesOf(ModuleGraph $graph): array
    {
        $sources = [];
        foreach ($graph->all() as $manifest) {
            $segments = explode('\\', trim($manifest->namespace, '\\'));
            $sources[] = [$manifest->id, $manifest->namespace, $this->projectDir.'/src/'.end($segments)];
        }

        return $sources;
    }

    private function key(string $name, int $version): string
    {
        return $name.'@'.$version;
    }
}
