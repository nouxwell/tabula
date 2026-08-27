<?php

declare(strict_types=1);

/*
 * Ana projedeki (crm-backend) kural seti @Symfony'dir; kütüphane onunla hizalı kalsın
 * diye aynı temel alınır. Fark: burada `declare(strict_types=1)` ZORUNLUDUR — ana projede
 * bu oran %2 civarındayken kütüphane sıfırdan başladığı için eşiği yüksek tutuyoruz.
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
