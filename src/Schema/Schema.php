<?php

declare(strict_types=1);

namespace Balin\Tabula\Schema;

use Balin\Tabula\Exception\SchemaException;
use Balin\Tabula\Format;
use Closure;

/**
 * Bir tablonun alan tanımlarının tamamı — TEK doğruluk kaynağı.
 *
 * Aynı şema üç yönü birden besler: dışa aktarma (xlsx/csv/pdf), içe aktarma ve şablon üretimi.
 * Bu yüzden dışa aktarılan bir dosya hiçbir dönüşüm olmadan geri içe aktarılabilir.
 */
final class Schema
{
    private string|Closure|null $title = null;

    /** @var array<string, Field> anahtara göre, tanım sırası korunur */
    private array $fields = [];

    private function __construct(
        private readonly string $name,
    ) {
    }

    public static function make(string $name): self
    {
        if ('' === trim($name)) {
            throw SchemaException::emptyName();
        }

        return new self($name);
    }

    /** Çeviri anahtarı, düz metin ya da fn(string $locale): string */
    public function title(string|Closure $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    /**
     * Alanları ekler. Aynı anahtar iki kez verilirse hata fırlatır — sessiz üzerine yazma yok.
     */
    public function fields(Field ...$fields): self
    {
        $clone = clone $this;

        foreach ($fields as $field) {
            $key = $field->getKey();

            if (isset($clone->fields[$key])) {
                throw SchemaException::duplicateField($this->name, $key);
            }

            $clone->fields[$key] = $field;
        }

        return $clone;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string|Closure|null
    {
        return $this->title;
    }

    /** @return array<string, Field> */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** @return list<string> */
    public function getKeys(): array
    {
        return array_keys($this->fields);
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function field(string $key): Field
    {
        return $this->fields[$key] ?? throw SchemaException::unknownField($this->name, $key, $this->getKeys());
    }

    public function isEmpty(): bool
    {
        return [] === $this->fields;
    }

    /**
     * Yalnızca verilen anahtarları, VERİLEN SIRAYLA içeren alt şema.
     *
     * Kullanıcının seçtiği kolonlar buradan geçer — istemci artık etiket değil, yalnız anahtar gönderir.
     * Bilinmeyen bir anahtar sessizce yutulmaz, hata olur.
     *
     * @param list<string> $keys
     */
    public function only(array $keys): self
    {
        if ([] === $keys) {
            throw SchemaException::emptySelection($this->name);
        }

        $clone = clone $this;
        $clone->fields = [];

        foreach ($keys as $key) {
            if (!isset($this->fields[$key])) {
                throw SchemaException::unknownField($this->name, $key, $this->getKeys());
            }

            $clone->fields[$key] = $this->fields[$key];
        }

        return $clone;
    }

    /** Verilen biçimde görünmeyen alanları (bkz. Field::only()) eler. */
    public function forFormat(Format $format): self
    {
        $clone = clone $this;
        $clone->fields = array_filter(
            $this->fields,
            static fn (Field $field): bool => $field->appliesTo($format),
        );

        return $clone;
    }

    /** @return list<Field> */
    public function required(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (Field $field): bool => $field->isRequired(),
        ));
    }
}
