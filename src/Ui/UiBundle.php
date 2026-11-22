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
 * the package renders wherever it is installed. It also owns the theme: the
 * Tailwind entry with the design tokens and the Inter font (@foresi/ui/theme),
 * the icons, the generic Stimulus controllers and the UX controllers they enable.
 */
final class UiBundle extends ModuleBundle
{
    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // <twig:Ui:Button> renders @Ui/components/Button.html.twig.
        $builder->prependExtensionConfig('twig_component', ['defaults' => [
            'Foresi\\Ui\\Component\\' => ['template_directory' => '@Ui/components/', 'name_prefix' => 'Ui'],
        ]]);
        $assets = $this->getPath().'/assets';
        if ($builder->hasExtension('framework')) {
            $builder->prependExtensionConfig('framework', ['asset_mapper' => ['paths' => [$assets.'/theme' => '@foresi/ui/theme']]]);
        }
        if ($builder->hasExtension('symfonycasts_tailwind')) {
            $builder->prependExtensionConfig('symfonycasts_tailwind', ['input_css' => [$assets.'/theme/app.css']]);
        }
        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', ['icon_dir' => $assets.'/icons']);
        }
        if ($builder->hasExtension('stimulus')) {
            $builder->prependExtensionConfig('stimulus', ['controllers_json' => $assets.'/controllers.json']);
        }
    }
}
