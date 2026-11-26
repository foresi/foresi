<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\DependencyInjection;

use Foresi\Inbox\Contract\InboxAction;
use Foresi\Inbox\Contract\InboxActionCondition;
use Foresi\Inbox\Contract\InboxActionInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails the container build for an operation declared as an inbox action that
 * Inbox could not run: its input must implement InboxActionInput, have a
 * constructor parameter inboxItemId, and name as its condition a class that is
 * an InboxActionCondition. It reads the handlers of every installed module, so
 * a module's mistake shows when the application is built, not on an item.
 */
final class InboxActionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds(OperationHandler::TAG)) as $id) {
            $definition = $container->findDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);
            if ($definition->isAbstract() || !\is_string($class) || !is_subclass_of($class, OperationHandler::class)) {
                continue;
            }
            self::check($class::input());
        }
    }

    /**
     * @param class-string $input
     *
     * @throws \LogicException for an input declared as an inbox action that Inbox cannot run
     */
    public static function check(string $input): void
    {
        $class = new \ReflectionClass($input);
        $attribute = ($class->getAttributes(InboxAction::class)[0] ?? null)?->newInstance();
        if (!$attribute instanceof InboxAction) {
            return;
        }
        if (!$class->implementsInterface(InboxActionInput::class)) {
            throw new \LogicException(\sprintf('The inbox action %s must implement %s.', $input, InboxActionInput::class));
        }
        $parameters = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $class->getConstructor()?->getParameters() ?? []);
        if (!\in_array('inboxItemId', $parameters, true)) {
            throw new \LogicException(\sprintf('The inbox action %s must have the field inboxItemId.', $input));
        }
        if (null !== $attribute->appliesTo && !is_subclass_of($attribute->appliesTo, InboxActionCondition::class)) {
            throw new \LogicException(\sprintf('The condition %s of the inbox action %s must implement %s.', $attribute->appliesTo, $input, InboxActionCondition::class));
        }
    }
}
