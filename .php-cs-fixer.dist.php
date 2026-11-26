<?php
// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config'])
    ->notPath('reference.php')
    ->exclude('e2e/node_modules')
    // A module of another vendor, not installed here (the External module workflow installs it).
    ->exclude('Fixtures/modules');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,
        'declare_strict_types' => true,
        'header_comment' => [
            // REUSE-IgnoreStart
            'header' => 'SPDX-License-Identifier: AGPL-3.0-only',
            // REUSE-IgnoreEnd
            'comment_type' => 'comment',
            'location' => 'after_open',
            'separate' => 'bottom',
        ],
    ])
    ->setFinder($finder);
