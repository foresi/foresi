<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Screen components, layout and navigation. Ui owns its templates (@Ui): the
 * components, the layouts, base.html.twig and the development catalogue, so
 * the package renders wherever it is installed.
 */
final class UiBundle extends ModuleBundle
{
    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // <twig:Ui:Button> renders @Ui/components/Button.html.twig.
        $builder->prependExtensionConfig('twig_component', ['defaults' => [
            'Foresi\\Ui\\Component\\' => ['template_directory' => '@Ui/components/', 'name_prefix' => 'Ui'],
        ]]);
    }
}
