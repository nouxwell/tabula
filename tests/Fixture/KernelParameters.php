<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Fixture;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The parameters a real Symfony kernel sets and a bare `ContainerBuilder` does not.
 *
 * Symfony 7.2 reads `kernel.environment` while loading a bundle extension and throws
 * `ParameterNotFoundException` without it; later versions stopped needing it. So a fixture that
 * omitted it passed on a current Symfony and failed on the oldest one composer.json claims to
 * support — a gap nothing could see until the suite was run against the LOWEST resolvable
 * dependencies rather than the newest.
 *
 * `kernel.default_locale` is deliberately NOT set here. One test asserts that the bundle emits a
 * PARAMETER REFERENCE rather than a resolved string, and it can only prove that while the
 * parameter is absent.
 */
final class KernelParameters
{
    public static function applyTo(ContainerBuilder $container): void
    {
        $root = \dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/tabula-kernel';

        foreach ([
            'kernel.environment' => 'test',
            'kernel.debug' => true,
            'kernel.project_dir' => $root,
            'kernel.build_dir' => $tmp.'/build',
            'kernel.cache_dir' => $tmp.'/cache',
            'kernel.logs_dir' => $tmp.'/logs',
            'kernel.charset' => 'UTF-8',
            'kernel.bundles' => [],
            'kernel.bundles_metadata' => [],
            'kernel.runtime_environment' => 'test',
        ] as $name => $value) {
            $container->setParameter($name, $value);
        }
    }
}
