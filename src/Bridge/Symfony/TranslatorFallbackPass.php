<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Bridge\Symfony;

use Nouxwell\Tabula\Port\PassthroughTranslator;
use Nouxwell\Tabula\Port\Translator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Binds the port to `PassthroughTranslator` when there is no Symfony translator.
 *
 * WHY THIS IS NEEDED: `TranslatorInterface` is NOT a service, it is merely an ALIAS set up in
 * FrameworkBundle's `translation.php` file, and that file is only loaded while
 * `framework.translator.enabled: true`. So if `symfony/translation` is not installed, or
 * translation has been switched off, `SymfonyTranslator`'s dependency on that alias brings the
 * ENTIRE application down at compile time:
 *
 *     ServiceNotFoundException: ... has a dependency on a non-existent service
 *     "Symfony\Contracts\Translation\TranslatorInterface".
 *
 * Yet the core carries `PassthroughTranslator` for exactly this situation. This pass is what
 * makes sure an application installing the library is not FORCED to use translation.
 */
final class TranslatorFallbackPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->has(TranslatorInterface::class)) {
            return;
        }

        if ($container->hasDefinition(SymfonyTranslator::class)) {
            $container->removeDefinition(SymfonyTranslator::class);
        }

        if ($container->hasAlias(Translator::class)) {
            $container->removeAlias(Translator::class);
        }

        $container->setDefinition(PassthroughTranslator::class, new Definition(PassthroughTranslator::class));
        $container->setAlias(Translator::class, PassthroughTranslator::class);
    }
}
