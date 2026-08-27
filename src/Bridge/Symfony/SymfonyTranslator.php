<?php

declare(strict_types=1);

namespace Balin\Tabula\Bridge\Symfony;

use Balin\Tabula\Port\Translator;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `Translator` portunun Symfony uygulaması.
 *
 * ÇEVİRİ ALANI SORUNU: Symfony katalogları alanlara (domain) bölünmüştür — ERP'de etiketler
 * `messages`, enum karşılıkları `enum` alanında durur. Ama enum'lar çeviri anahtarını
 * `label(): string` ile döndürür ve o anahtar alan bilgisi TAŞIMAZ. 200'den fazla mevcut
 * enum'a dokunmadan bunu çözmek için iki yol vardır:
 *
 *  1. AÇIK ÖNEK — `enum:purchase_status.open`. Önek `addressableDomains` listesindeyse alan
 *     sayılır; aksi hâlde anahtarın parçası kabul edilir (içinde iki nokta geçen anahtarların
 *     yanlışlıkla bölünmesini önler).
 *  2. ALAN ZİNCİRİ — önek yoksa `domains` sırayla denenir ve İLK TANIMLI alan kazanır.
 *
 * ISABET TESPİTİ — burası sinsi. İlk sürüm "Symfony ıskada anahtarı geri döndürür" varsayıp
 * dönen dizeyi `strtr($key, $params)` ile karşılaştırıyordu. Bu ÜÇ durumda yanlış sonuç verir
 * (üçü de gerçek `Symfony\Component\Translation\Translator` ile doğrulandı):
 *
 *   a) YANLIŞ ISKA — kendi anahtarına eşit çeviri. `messages: ['IBAN' => 'IBAN']` tanımlıdır
 *      ama ıska sanılır ve zincirdeki SONRAKİ alan sessizce kazanır. ERP'de kendine eşit
 *      çeviri hiç de nadir değildir (IBAN, KDV, ISO kodları, birim simgeleri).
 *   b) YANLIŞ ISABET — ICU alanı (`messages+intl-icu`). Iskada ICU biçimlendirici
 *      `%name%` yerleştirmesi YAPMAZ; dönen dize `strtr` sonucuna eşit olmaz, zincir orada
 *      durur ve hücreye ham anahtar yazılır.
 *   c) YANLIŞ ISABET — `|` içeren çoğul anahtarlar. Iskayı `IdentityTranslator` çoğul
 *      seçimiyle biçimlendirir, `strtr` ile değil.
 *
 * Çözüm: dönen dizeden isabet ÇIKARMAK yerine kataloğa SORMAK. Symfony'nin gerçek
 * translator'ı (ve Logging/DataCollector sarmalayıcıları) `TranslatorBagInterface` uygular;
 * `defines()` kendi yedek kataloglarını gezmediği için yedek zinciri elle yürünür.
 * `TranslatorBagInterface` uygulamayan bir translator verilirse eski sezgisel yönteme
 * düşülür — kusurlu ama hiç yoktan iyidir.
 */
final readonly class SymfonyTranslator implements Translator
{
    /** @var list<string> */
    private array $addressableDomains;

    /**
     * @param list<string>      $domains            sırayla denenecek çeviri alanları
     * @param list<string>|null $addressableDomains `alan:anahtar` önekiyle erişilebilen alanlar
     *                                              (null = `$domains` ile aynı)
     */
    public function __construct(
        private TranslatorInterface $translator,
        private array $domains = ['messages'],
        private string $domainSeparator = ':',
        ?array $addressableDomains = null,
    ) {
        // Açıkça adreslenebilir alanlar zincirin ÜST KÜMESİDİR: zincirde olan her alan
        // önekle de çağrılabilmelidir, ayrıca zincir dışı alanlar (ör. `validators`) da
        // listelenebilir. Aksi hâlde önek yalnızca zaten zincirde olan — yani öneke ihtiyaç
        // duymayan — alanlarda çalışırdı ki bu, özelliğin tam tersi olurdu.
        $this->addressableDomains = array_values(array_unique(
            [...$domains, ...($addressableDomains ?? [])],
        ));
    }

    public function trans(string $key, array $params = [], ?string $locale = null): string
    {
        $normalized = $this->normalizeParams($params);
        [$explicitDomain, $id] = $this->splitDomain($key);

        if (null !== $explicitDomain) {
            return $this->translator->trans($id, $normalized, $explicitDomain, $locale);
        }

        if ($this->translator instanceof TranslatorBagInterface) {
            $domain = $this->findDefiningDomain($id, $locale);

            return null === $domain
                ? strtr($id, $normalized)
                : $this->translator->trans($id, $normalized, $domain, $locale);
        }

        return $this->guessByComparison($id, $normalized, $locale);
    }

    /**
     * Anahtarı TANIMLAYAN ilk alanı bulur; yedek kataloglar da gezilir.
     *
     * ICU alanları ayrı bir katalog alanında (`<domain>+intl-icu`) durduğu için ikisi de sorulur.
     */
    private function findDefiningDomain(string $id, ?string $locale): ?string
    {
        \assert($this->translator instanceof TranslatorBagInterface);

        $catalogue = $this->translator->getCatalogue($locale);

        while (null !== $catalogue) {
            foreach ($this->domains as $domain) {
                if ($catalogue->defines($id, $domain)
                    || $catalogue->defines($id, $domain.MessageCatalogueInterface::INTL_DOMAIN_SUFFIX)
                ) {
                    return $domain;
                }
            }

            $catalogue = $catalogue->getFallbackCatalogue();
        }

        return null;
    }

    /**
     * `TranslatorBagInterface` uygulamayan translator'lar için yedek sezgisel yöntem.
     *
     * Yukarıda anlatılan üç kusuru taşır; yalnızca kataloğa soramadığımız için buradadır.
     *
     * @param array<string, string> $normalized
     */
    private function guessByComparison(string $id, array $normalized, ?string $locale): string
    {
        $miss = strtr($id, $normalized);

        foreach ($this->domains as $domain) {
            $translated = $this->translator->trans($id, $normalized, $domain, $locale);

            if ($translated !== $miss) {
                return $translated;
            }
        }

        // Hiçbir alanda yok: anahtarın kendisi görünür kalsın — hücre asla boş kalmaz,
        // ve eksik çeviri çıktıda gözle görülür (sessizce boşluk bırakmaktan iyidir).
        return $miss;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function splitDomain(string $key): array
    {
        $position = strpos($key, $this->domainSeparator);

        if (false === $position || 0 === $position) {
            return [null, $key];
        }

        $prefix = substr($key, 0, $position);

        if (!in_array($prefix, $this->addressableDomains, true)) {
            return [null, $key];
        }

        $id = substr($key, $position + strlen($this->domainSeparator));

        // `enum:` gibi gövdesiz bir anahtar boş hücre üretirdi; bozuk anahtarı olduğu gibi
        // göstermek, sessizce boşluk bırakmaktan iyidir.
        return '' === $id ? [null, $key] : [$prefix, $id];
    }

    /**
     * Kütüphane parametreleri sade adla verir (`['count' => 5]`); Symfony ise yer tutucu
     * biçimini bekler (`['%count%' => 5]`). Zaten yüzdeyle sarılmış adlar olduğu gibi geçer.
     *
     * @param array<string, string|int|float> $params
     *
     * @return array<string, string>
     */
    private function normalizeParams(array $params): array
    {
        $normalized = [];

        foreach ($params as $name => $value) {
            $normalized['%'.trim($name, '%').'%'] = (string) $value;
        }

        return $normalized;
    }
}
