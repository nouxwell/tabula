<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Schema;

use Balin\Tabula\Format;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Schema\Priority;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Field sözleşmesinin ana başlığı DEĞİŞMEZLİKTİR.
 *
 * Eski ERP motorunda kolon tanımları paylaşılan, değiştirilebilir dizilerdi: bir ekranda
 * yapılan genişlik ayarı aynı tanımı kullanan başka bir dışa aktarmayı da sessizce bozuyordu.
 * Burada her akıcı metot kopya döndürdüğü için asıl sorulan soru "özgün nesne dokunulmadan
 * kaldı mı" olur; aşağıdaki testlerin çoğu bunu ölçer.
 */
#[CoversClass(Field::class)]
#[CoversClass(Align::class)]
#[CoversClass(FieldType::class)]
final class FieldTest extends TestCase
{
    // ---------------------------------------------------------------- değişmezlik

    #[Test]
    public function fluentSetterReturnsACloneAndLeavesTheOriginalUntouched(): void
    {
        $a = Field::string('x');
        $b = $a->label('L');

        self::assertNotSame($a, $b, 'Akıcı metot yeni bir nesne döndürmeli.');
        self::assertNull($a->getLabel(), 'Özgün alan değişmemeli.');
        self::assertSame('L', $b->getLabel());

        // Anahtar ve tip kopyaya aynen taşınır.
        self::assertSame('x', $a->getKey());
        self::assertSame('x', $b->getKey());
        self::assertSame(FieldType::String, $b->getType());
    }

    /**
     * Tek tek her akıcı metot için aynı üç şeyi doğrular: yeni nesne, özgünde
     * eski değer, kopyada yeni değer.
     *
     * @param Closure(Field): Field $mutate
     * @param Closure(Field): mixed $read
     */
    #[Test]
    #[DataProvider('fluentSetters')]
    public function everyFluentSetterIsImmutable(Closure $mutate, Closure $read, mixed $before, mixed $after): void
    {
        $original = Field::string('x');
        $changed = $mutate($original);

        self::assertNotSame($original, $changed);
        self::assertSame($before, $read($original));
        self::assertSame($after, $read($changed));
    }

    /**
     * @return iterable<string, array{Closure(Field): Field, Closure(Field): mixed, mixed, mixed}>
     */
    public static function fluentSetters(): iterable
    {
        yield 'label' => [
            static fn (Field $f): Field => $f->label('export.customer.code'),
            static fn (Field $f): mixed => $f->getLabel(),
            null,
            'export.customer.code',
        ];

        yield 'from' => [
            static fn (Field $f): Field => $f->from('address.city'),
            static fn (Field $f): mixed => $f->getFrom(),
            null,
            'address.city',
        ];

        yield 'decimals' => [
            static fn (Field $f): Field => $f->decimals(4),
            static fn (Field $f): mixed => $f->getDecimals(),
            null,
            4,
        ];

        yield 'currency' => [
            static fn (Field $f): Field => $f->currency('TRY'),
            static fn (Field $f): mixed => $f->getCurrency(),
            null,
            'TRY',
        ];

        yield 'width' => [
            static fn (Field $f): Field => $f->width(32),
            static fn (Field $f): mixed => $f->getWidth(),
            null,
            32,
        ];

        yield 'align' => [
            static fn (Field $f): Field => $f->align(Align::Right),
            static fn (Field $f): mixed => $f->getAlign(),
            Align::Left,
            Align::Right,
        ];

        yield 'required' => [
            static fn (Field $f): Field => $f->required(),
            static fn (Field $f): mixed => $f->isRequired(),
            false,
            true,
        ];

        yield 'priority' => [
            static fn (Field $f): Field => $f->priority(Priority::Always),
            static fn (Field $f): mixed => $f->getPriority(),
            Priority::Normal,
            Priority::Always,
        ];

        yield 'only' => [
            static fn (Field $f): Field => $f->only(Format::Xlsx),
            static fn (Field $f): mixed => $f->getOnly(),
            null,
            [Format::Xlsx],
        ];

        yield 'pattern' => [
            static fn (Field $f): Field => $f->pattern('d.m.Y'),
            static fn (Field $f): mixed => $f->getPattern(),
            null,
            'd.m.Y',
        ];

        // Kapanış kimliği yerine "var mı" sorusu sorulur; iki ayrı kapanış asla eşit olmaz.
        yield 'format' => [
            static fn (Field $f): Field => $f->format(static fn (mixed $raw): string => (string) $raw),
            static fn (Field $f): mixed => null !== $f->getFormatter(),
            false,
            true,
        ];
    }

    #[Test]
    public function chainingDoesNotLeakBackIntoEarlierLinks(): void
    {
        $base = Field::money('balance');
        $withLabel = $base->label('L');
        $withWidth = $withLabel->width(20);

        self::assertNull($base->getLabel());
        self::assertNull($base->getWidth());
        self::assertNull($withLabel->getWidth());
        self::assertSame('L', $withWidth->getLabel());
        self::assertSame(20, $withWidth->getWidth());
    }

    #[Test]
    public function settersOverwriteRatherThanAccumulate(): void
    {
        $field = Field::string('s')->width(20)->width(null);
        self::assertNull($field->getWidth());

        $required = Field::string('s')->required()->required(false);
        self::assertFalse($required->isRequired());

        // only() birikmez, tümüyle değiştirir.
        $formats = Field::string('s')->only(Format::Xlsx)->only(Format::Csv, Format::Pdf);
        self::assertSame([Format::Csv, Format::Pdf], $formats->getOnly());
    }

    // ---------------------------------------------------------------- kaynak

    #[Test]
    public function sourceFallsBackToTheKeyWhenFromIsNotGiven(): void
    {
        self::assertSame('code', Field::string('code')->getSource());
        self::assertNull(Field::string('code')->getFrom());
    }

    #[Test]
    public function sourceUsesFromWhenGiven(): void
    {
        self::assertSame('c.code', Field::string('code')->from('c.code')->getSource());
    }

    #[Test]
    public function sourceKeepsAClosureAsIs(): void
    {
        $reader = static fn (mixed $row): string => 'x';
        $field = Field::string('code')->from($reader);

        self::assertSame($reader, $field->getFrom());
        self::assertSame($reader, $field->getSource());
    }

    // ---------------------------------------------------------------- hizalama

    /** Hizalama dışarıya hiçbir zaman `Auto` sızdırmaz; yazıcılar somut bir değer bekler. */
    #[Test]
    #[DataProvider('everyFieldType')]
    public function alignIsAlwaysResolvedNeverAuto(FieldType $type): void
    {
        self::assertNotSame(Align::Auto, self::fieldOfType($type)->getAlign());
    }

    #[Test]
    public function alignFollowsTheTypeByDefault(): void
    {
        self::assertSame(Align::Left, Field::string('s')->getAlign());
        self::assertSame(Align::Right, Field::money('m')->getAlign());
        self::assertSame(Align::Right, Field::integer('i')->getAlign());
        self::assertSame(Align::Right, Field::decimal('d')->getAlign());
        self::assertSame(Align::Right, Field::quantity('q')->getAlign());
        self::assertSame(Align::Center, Field::bool('b')->getAlign());
        self::assertSame(Align::Center, Field::date('d')->getAlign());
        self::assertSame(Align::Center, Field::dateTime('dt')->getAlign());
        self::assertSame(Align::Left, Field::enum('e', Priority::class)->getAlign());
        self::assertSame(Align::Left, Field::options('o', ['a' => 'A'])->getAlign());
    }

    #[Test]
    public function explicitAlignBeatsTheTypeDefault(): void
    {
        self::assertSame(Align::Center, Field::money('m')->align(Align::Center)->getAlign());
    }

    #[Test]
    public function explicitAutoStillResolvesFromTheType(): void
    {
        // Auto'yu elle vermek "varsayılana dön" demektir, "hizalama yok" demek değil.
        self::assertSame(Align::Right, Field::money('m')->align(Align::Auto)->getAlign());
    }

    // ---------------------------------------------------------------- biçim kapsamı

    #[Test]
    public function fieldAppliesToEveryFormatByDefault(): void
    {
        $field = Field::string('s');

        self::assertNull($field->getOnly());
        self::assertTrue($field->appliesTo(Format::Xlsx));
        self::assertTrue($field->appliesTo(Format::Csv));
        self::assertTrue($field->appliesTo(Format::Pdf));
    }

    #[Test]
    public function onlyRestrictsTheFieldToTheGivenFormats(): void
    {
        $field = Field::string('internalId')->only(Format::Xlsx);

        self::assertSame([Format::Xlsx], $field->getOnly());
        self::assertTrue($field->appliesTo(Format::Xlsx));
        self::assertFalse($field->appliesTo(Format::Csv));
        self::assertFalse($field->appliesTo(Format::Pdf));
    }

    #[Test]
    public function onlyAcceptsSeveralFormats(): void
    {
        $field = Field::string('s')->only(Format::Csv, Format::Pdf);

        self::assertFalse($field->appliesTo(Format::Xlsx));
        self::assertTrue($field->appliesTo(Format::Csv));
        self::assertTrue($field->appliesTo(Format::Pdf));
    }

    /**
     * Keskin kenar: `only()` argümansız çağrılırsa alan HİÇBİR biçimde görünmez.
     * Bilerek yazıldığında "şimdilik kapalı" anlamına gelir; kazara yazıldığında
     * alan sessizce kaybolur. Davranış burada kayda geçirilmiştir.
     */
    #[Test]
    public function onlyWithoutArgumentsHidesTheFieldEverywhere(): void
    {
        $field = Field::string('s')->only();

        self::assertSame([], $field->getOnly());
        self::assertFalse($field->appliesTo(Format::Xlsx));
        self::assertFalse($field->appliesTo(Format::Csv));
        self::assertFalse($field->appliesTo(Format::Pdf));
    }

    // ---------------------------------------------------------------- tipe özel ekler

    #[Test]
    public function enumFieldStoresItsEnumClass(): void
    {
        $field = Field::enum('priority', Priority::class);

        self::assertSame(FieldType::Enum, $field->getType());
        self::assertSame(Priority::class, $field->getEnumClass());
        self::assertNull($field->getOptions());
        self::assertTrue($field->getType()->isEnumerable());
    }

    #[Test]
    public function optionsFieldStoresAStaticList(): void
    {
        $options = ['draft' => 'Taslak', 'posted' => 'İşlendi'];
        $field = Field::options('status', $options);

        self::assertSame(FieldType::Options, $field->getType());
        self::assertSame($options, $field->getOptions());
        self::assertNull($field->getEnumClass());
    }

    #[Test]
    public function optionsFieldStoresAClosureUnevaluated(): void
    {
        $resolver = static fn (): array => ['a' => 'A'];
        $field = Field::options('status', $resolver);

        // Kapanış çalışma anına saklanır; tanım anında çözülmez.
        self::assertSame($resolver, $field->getOptions());
    }

    #[Test]
    public function enumExtrasAreCarriedThroughFluentClones(): void
    {
        $field = Field::enum('priority', Priority::class)->label('L')->width(12);

        self::assertSame(Priority::class, $field->getEnumClass());
        self::assertSame(FieldType::Enum, $field->getType());
    }

    #[Test]
    public function plainFieldsCarryNoEnumOrOptionExtras(): void
    {
        $field = Field::string('s');

        self::assertNull($field->getEnumClass());
        self::assertNull($field->getOptions());
    }

    // ---------------------------------------------------------------- varsayılanlar

    #[Test]
    public function freshFieldHasQuietDefaults(): void
    {
        $field = Field::string('s');

        self::assertNull($field->getLabel());
        self::assertNull($field->getFrom());
        self::assertNull($field->getDecimals());
        self::assertNull($field->getCurrency());
        self::assertNull($field->getWidth());
        self::assertNull($field->getPattern());
        self::assertNull($field->getFormatter());
        self::assertNull($field->getOnly());
        self::assertFalse($field->isRequired());
        self::assertSame(Priority::Normal, $field->getPriority());
    }

    #[Test]
    #[DataProvider('everyFieldType')]
    public function everyConstructorProducesItsOwnType(FieldType $type): void
    {
        $field = self::fieldOfType($type);

        self::assertSame($type, $field->getType());
        self::assertSame('f', $field->getKey());
        self::assertSame('f', $field->getSource());
    }

    #[Test]
    public function closureLabelAndCurrencyAreKeptForLateResolution(): void
    {
        $label = static fn (string $locale): string => 'tr' === $locale ? 'Kod' : 'Code';
        $currency = static fn (mixed $row): string => 'TRY';

        $field = Field::money('balance')->label($label)->currency($currency);

        self::assertSame($label, $field->getLabel());
        self::assertSame($currency, $field->getCurrency());
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return iterable<string, array{FieldType}> */
    public static function everyFieldType(): iterable
    {
        foreach (FieldType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    /** Her tip için o tipin kendi kurucusunu kullanır — kurucu private olduğu için tek yol bu. */
    private static function fieldOfType(FieldType $type): Field
    {
        return match ($type) {
            FieldType::String => Field::string('f'),
            FieldType::Integer => Field::integer('f'),
            FieldType::Decimal => Field::decimal('f'),
            FieldType::Money => Field::money('f'),
            FieldType::Quantity => Field::quantity('f'),
            FieldType::Bool => Field::bool('f'),
            FieldType::Date => Field::date('f'),
            FieldType::DateTime => Field::dateTime('f'),
            FieldType::Enum => Field::enum('f', Priority::class),
            FieldType::Options => Field::options('f', ['a' => 'A']),
        };
    }
}
