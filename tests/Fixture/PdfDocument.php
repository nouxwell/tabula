<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Fixture;

use Balin\Tabula\Export\Page\Page;
use PHPUnit\Framework\Assert;

/**
 * Üretilen bir PDF'in GEOMETRİSİNİ okuyan minik okuyucu.
 *
 * Sayfa ayarının uygulandığını "nesne kuruldu" düzeyinde doğrulamak bu kütüphanede yetmez:
 * bu fazın var olma sebebi, kurulan ama çıktıya hiç inmeyen bir kâğıt ayarıydı. İddia bu
 * yüzden belgenin baytlarından kurulur.
 *
 * Metin akışları sıkıştırıldığı için hücre içeriği greplenemez; geometri greplenebilir:
 *  - `/MediaBox [0 0 en boy]` → kâğıdın punto cinsinden gerçek ölçüsü,
 *  - `/Type /Page` → her bir kâğıt (`/Type /Pages` ağacın kökü, kâğıt değil).
 *
 * Beklenen ölçü ELLE YAZILMAZ, `Page`in kendisinden türetilir (`assertPaper`): "A4 yatay
 * 841.89 punto" diye bir sabit, kâğıt tablosunun ikinci bir kopyası olurdu ve ikisi
 * ayrıştığında test, koda değil kendi sabitine bakıyor olurdu.
 */
final class PdfDocument
{
    /** Bir milimetrenin punto karşılığı; PDF birimi 1/72 inçtir. */
    private const float POINTS_PER_MM = 72.0 / 25.4;

    /**
     * Yarım puntoluk tolerans: Dompdf `/MediaBox` değerlerini üç haneye yuvarlar, yani
     * 210 mm kâğıt 595.276 punto olarak basılır ve tam eşitlik hiçbir zaman tutmaz.
     */
    private const float TOLERANCE_PT = 0.5;

    private function __construct(
        private readonly string $path,
        private readonly string $bytes,
    ) {
    }

    public static function at(string $path): self
    {
        Assert::assertFileExists($path);

        return new self($path, (string) file_get_contents($path));
    }

    /** Belgeye BASILMIŞ kâğıt ölçüsünün beklenen `Page` ile aynı olduğunu doğrular. */
    public function assertPaper(Page $expected): void
    {
        [$width, $height] = $this->paper();

        Assert::assertEqualsWithDelta(
            self::toPoints($expected->widthMm()),
            $width,
            self::TOLERANCE_PT,
            sprintf('"%s" belgesinin kâğıt eni beklenenden farklı.', $this->path),
        );

        Assert::assertEqualsWithDelta(
            self::toPoints($expected->heightMm()),
            $height,
            self::TOLERANCE_PT,
            sprintf('"%s" belgesinin kâğıt boyu beklenenden farklı.', $this->path),
        );
    }

    public function assertPageCount(int $expected): void
    {
        Assert::assertSame(
            $expected,
            $this->pageCount(),
            sprintf('"%s" belgesinin kâğıt sayısı beklenenden farklı.', $this->path),
        );
    }

    /**
     * Belgedeki ilk `/MediaBox` girdisi, punto olarak.
     *
     * @return array{float, float} en, boy
     */
    public function paper(): array
    {
        if (1 !== preg_match('#/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)#', $this->bytes, $found)) {
            Assert::fail(sprintf('"%s" belgesinde /MediaBox yok — Dompdf çıktısının biçimi değişmiş olabilir.', $this->path));
        }

        return [(float) $found[1], (float) $found[2]];
    }

    /** Belgedeki kâğıt sayısı. */
    public function pageCount(): int
    {
        // `[^s]` eki `/Type /Pages` (sayfa ağacının KÖKÜ) girdisini eler. `/Count` alanına
        // bakılmıyor: CPDF çıktısında boş bir `/Count 0` de bulunuyor ve hangisinin gerçek
        // olduğunu ayıklamak fazladan iş olurdu.
        return (int) preg_match_all('#/Type\s*/Page[^s]#', $this->bytes);
    }

    private static function toPoints(float $mm): float
    {
        return $mm * self::POINTS_PER_MM;
    }
}
