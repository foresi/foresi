<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\DependencyInjection;

use Foresi\Inbox\Contract\InboxAction;
use Foresi\Inbox\Contract\InboxActionCondition;
use Foresi\Inbox\Contract\InboxActionInput;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails the container build for an operation declared as an inbox action that
 * Inbox could not run: its input must implement InboxActionInput, have the
 * fields inboxItemId (a string, named in the path) and markDone (a bool), and
 * name as its condition an InboxActionCondition registered as a service. It reads the handlers of every installed module, so
 * a module's mistake shows when the application is built, not on an item.
 */
final class InboxActionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $conditions = [];
        foreach (array_keys($container->findTaggedServiceIds(InboxActionCondition::TAG)) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass() ?? $id);
            if (\is_string($class)) {
                $conditions[] = $class;
            }
        }
        foreach (array_keys($container->findTaggedServiceIds(OperationHandler::TAG)) as $id) {
            $definition = $container->findDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);
            if ($definition->isAbstract() || !\is_string($class) || !is_subclass_of($class, OperationHandler::class)) {
                continue;
            }
            self::check($class::input(), $conditions);
        }
    }

    /**
     * @param class-string      $input
     * @param list<string>|null $conditions the classes of the condition services; null leaves them unchecked
     *
     * @throws \LogicException for an input declared as an inbox action that Inbox cannot run
     */
    public static function check(string $input, ?array $conditions = null): void
    {
        $class = new \ReflectionClass($input);
        $attribute = ($class->getAttributes(InboxAction::class)[0] ?? null)?->newInstance();
        if (!$attribute instanceof InboxAction) {
            return;
        }
        if (!$class->implementsInterface(InboxActionInput::class)) {
            throw new \LogicException(\sprintf('The inbox action %s must implement %s.', $input, InboxActionInput::class));
        }
        $types = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $types[$parameter->getName()] = $type instanceof \ReflectionNamedType ? $type->getName() : null;
        }
        if ('string' !== ($types['inboxItemId'] ?? null)) {
            throw new \LogicException(\sprintf('The inbox action %s must have the field inboxItemId, a string.', $input));
        }
        $operation = ($class->getAttributes(Operation::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance();
        if ($operation instanceof Operation && !str_contains($operation->path, '{inboxItemId}')) {
            throw new \LogicException(\sprintf('The path of the inbox action %s must name {inboxItemId}.', $input));
        }
        if ('bool' !== ($types['markDone'] ?? null)) {
            throw new \LogicException(\sprintf('The inbox action %s must have the field markDone, a bool.', $input));
        }
        if (null !== $attribute->appliesTo && !is_subclass_of($attribute->appliesTo, InboxActionCondition::class)) {
            throw new \LogicException(\sprintf('The condition %s of the inbox action %s must implement %s.', $attribute->appliesTo, $input, InboxActionCondition::class));
        }
        if (null !== $attribute->appliesTo && null !== $conditions && !\in_array($attribute->appliesTo, $conditions, true)) {
            throw new \LogicException(\sprintf('The condition %s of the inbox action %s is no service; register it as one.', $attribute->appliesTo, $input));
        }
    }
}
