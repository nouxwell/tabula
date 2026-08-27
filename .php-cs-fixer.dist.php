<?php

declare(strict_types=1);

/*
 * The rule set of the application this package was extracted from is @Symfony; the same base is
 * taken here so the library stays aligned with it. The difference: `declare(strict_types=1)` is
 * MANDATORY here — in that application the ratio sits at around 2%, but since the library starts
 * from scratch we keep the bar high.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => false,
        'phpdoc_to_comment' => false,
        'concat_space' => ['spacing' => 'none'],
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
    ])
    ->setFinder($finder);
