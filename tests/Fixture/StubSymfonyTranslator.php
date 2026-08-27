<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Fixture;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Kapsayıcı testlerinde `symfony/translation` yerine geçen en küçük uygulama.
 *
 * Gerçek paket kurulu değil (yalnızca `translation-contracts` var), ama köprü
 * `service(TranslatorInterface::class)` referansı kuruyor; derleme sırasında bu kimlikte
 * bir servis bulunamazsa kapsayıcı hiç ayağa kalkmaz. Referansın çözülebilmesi için SOMUT
 * bir sınıf şarttır — anonim sınıf kapsayıcıya tanım (Definition) olarak verilemez.
 *
 * Eksik anahtarda Symfony'nin davranışını taklit eder: ANAHTARIN KENDİSİNİ döndürür.
 * `SymfonyTranslator`'ın alan zinciri tam olarak bu sinyale bakıp sıradaki alana geçtiği
 * için, "isabet yok" davranışını doğru taklit etmek zincir testinin ön şartıdır.
 */
final readonly class StubSymfonyTranslator implements TranslatorInterface
{
    /**
     * @param array<string, array<string, string>> $catalogue alan => (anahtar => metin)
     */
    public function __construct(
        private array $catalogue = [],
        private string $locale = 'en',
    ) {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->catalogue[$domain ?? 'messages'][$id] ?? $id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}
