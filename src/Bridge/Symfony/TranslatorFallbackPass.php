<?php

declare(strict_types=1);

namespace Balin\Tabula\Bridge\Symfony;

use Balin\Tabula\Port\PassthroughTranslator;
use Balin\Tabula\Port\Translator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Symfony çevirmeni yoksa portu `PassthroughTranslator`a bağlar.
 *
 * NEDEN GEREKLİ: `TranslatorInterface` bir servis DEĞİL, yalnızca FrameworkBundle'ın
 * `translation.php` dosyasında kurulan bir TAKMA ADDIR ve o dosya yalnızca
 * `framework.translator.enabled: true` iken yüklenir. Yani `symfony/translation` kurulu
 * değilse ya da çeviri kapatılmışsa, `SymfonyTranslator`ın bu takma ada olan bağımlılığı
 * uygulamanın TAMAMINI derleme anında düşürür:
 *
 *     ServiceNotFoundException: ... has a dependency on a non-existent service
 *     "Symfony\Contracts\Translation\TranslatorInterface".
 *
 * Oysa çekirdek tam bu durum için `PassthroughTranslator` taşıyor. Bu geçiş, kütüphaneyi
 * kuran bir uygulamanın çeviri kullanmak ZORUNDA kalmamasını sağlar.
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
