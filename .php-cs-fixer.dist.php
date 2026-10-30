<?php
// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config'])
    ->notPath('reference.php')
    ->exclude('e2e/node_modules');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,
        'declare_strict_types' => true,
        'header_comment' => [
            'header' => 'SPDX-License-Identifier: AGPL-3.0-only',
            'comment_type' => 'comment',
            'location' => 'after_open',
            'separate' => 'bottom',
        ],
    ])
    ->setFinder($finder);
