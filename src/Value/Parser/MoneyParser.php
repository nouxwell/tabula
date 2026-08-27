<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Parser;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Value\Formatter\NumberFormatter;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ValueParser;
use Stringable;

/**
 * Para alanlarının ayrıştırıcısı.
 *
 * Sayı çözümlemesi `NumberFormatter::parse()` ile ORTAKtır (bkz. `NumberParser`); bu
 * sınıfın tek ek işi SİMGEYİ SÖKMEKtir. Dışa aktarma simgeyi hücrenin biçim koduna
 * koyar, metne değil — ama kullanıcı şablonu doldururken "1.234,56 ₺" yapıştırır,
 * başka bir sistemden "$1,234.56" kopyalar.
 *
 * Simgeyi elle sökmek gereksiz görünebilir: `NumberFormatter` rakam ve ayırıcı dışındaki
 * her karakteri zaten siliyor. Ama sildiği için tehlikelidir — içinde nokta taşıyan bir
 * simge (`S/.`, `Kč` gibi kodlarla birlikte kullanılan gösterimler) temizlik sonrası
 * "1.234,56." dizesi bırakır; en sağdaki nokta artık ondalık sayılmaz ve değer 123456
 * olarak, yani YÜZ KAT büyük okunur. Muhasebe verisinde yapılabilecek en pahalı hata
 * budur, bu yüzden simge sayıya girmeden önce sökülür.
 *
 * Eksi işareti, parantez ve sondaki eksi KORUNUR: işaret tespiti `NumberFormatter`ın
 * içinde ham metin üzerinde yapılır, burada silinirse bir borç sessizce alacağa döner.
 */
final class MoneyParser implements ValueParser
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Money === $type;
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        if (StringParser::isBlank($raw)) {
            return null;
        }

        $numbers = $context->settings->numbers;
        $candidate = $raw;

        if ($candidate instanceof Stringable) {
            $candidate = (string) $candidate;
        }

        if (is_string($candidate)) {
            $candidate = $this->stripSymbols($candidate, $field, $numbers);
        }

        // `preferLocalized: true` — bkz. NumberParser. Para tarafında bu daha da kritikti:
        // simge soyulunca "1.234 ₺" metni "1.234"e dönüyor, kanonik kısayola düşüyor ve 1234
        // yerine 1.234 okunuyordu. Yani KENDİ dışa aktarmamız kendi içe aktarmamızı bozuyordu
        // ve geçerli bir float üretildiği için hiçbir yerde hata görünmüyordu.
        $amount = NumberFormatter::parse($candidate, $numbers, preferLocalized: true);

        // Hata mesajında HAM hücre görünür; simgesi sökülmüş ara hâli değil.
        if (null === $amount) {
            throw ParseException::notANumber($field, StringParser::describe($raw));
        }

        // Para daima `float`: aynı kolonun bir satırda `int`, diğerinde `float` dönmesi,
        // tutarı alan tarafta sessiz tip sürprizleri üretir (bkz. NumberParser).
        return (float) $amount;
    }

    private function stripSymbols(string $text, Field $field, NumberSettings $numbers): string
    {
        $text = StringParser::clean($text);

        foreach ($this->symbolCandidates($field, $numbers) as $symbol) {
            $quoted = preg_quote($symbol, '/');
            // Yalnız BAŞTAKİ ve SONDAKİ geçiş sökülür; sayının içine karışan bir şey varsa
            // orası zaten `NumberFormatter`ın temizlik adımının işidir.
            $text = preg_replace('/^\s*'.$quoted.'\s*|\s*'.$quoted.'\s*$/iu', '', $text) ?? $text;
        }

        return trim($text);
    }

    /**
     * Sökülecek simge adayları — uzundan kısaya.
     *
     * Sıra önemlidir: "US$" adayı "$"tan önce denenmezse geriye tek başına "US" kalır.
     * Yapılandırılmış TÜM simgeler (ve kodları) listeye girer, çünkü tek bir listede TRY
     * ve USD satırları yan yana durabilir; alanın varsayılan para birimi hücredekiyle
     * aynı olmak zorunda değildir.
     *
     * @return list<string>
     */
    private function symbolCandidates(Field $field, NumberSettings $numbers): array
    {
        $candidates = array_merge(
            array_values($numbers->currencySymbols),
            array_keys($numbers->currencySymbols),
        );

        $code = $this->currencyCode($field);
        if (null !== $code) {
            $candidates[] = $code;
            $symbol = $numbers->symbolFor($code);
            if (null !== $symbol) {
                $candidates[] = $symbol;
            }
        }

        $separators = [$numbers->decimalSeparator, $numbers->thousandSeparator];

        $candidates = array_filter(
            $candidates,
            static function (string $candidate) use ($separators): bool {
                $candidate = trim($candidate);

                // Sayının parçası olabilecek aday sökülmez: yapılandırmada simge olarak
                // "." ya da "," duruyorsa onu silmek tutarı bin katına çıkarırdı.
                return '' !== $candidate
                    && !in_array($candidate, $separators, true)
                    && 1 !== preg_match('/^[0-9.,\s]+$/u', $candidate);
            },
        );

        $candidates = array_values(array_unique(array_map(trim(...), $candidates)));

        usort($candidates, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $candidates;
    }

    /**
     * Alanın sabit para birimi kodu.
     *
     * Kapanışla verilen para birimi (`->currency(fn ($row) => $row['currencyCode'])`)
     * burada ÇÖZÜLEMEZ: kapanış satırı bekler, içe aktarmada ise henüz satır yoktur.
     * Kapanışı `null` ile çağırmak `TypeError` üretirdi; bu yüzden atlanır (kapanış da,
     * `null` da tek bir `is_string` denetimiyle elenir) —
     * kaybedilen tek şey simge sökme kolaylığıdır, sayının kendisi yine doğru okunur
     * (simge zaten yapılandırılmış diğer adaylarla ya da `NumberFormatter`ın temizlik
     * adımıyla düşer).
     */
    private function currencyCode(Field $field): ?string
    {
        $currency = $field->getCurrency();

        if (!is_string($currency)) {
            return null;
        }

        $code = trim($currency);

        return '' === $code ? null : $code;
    }
}
