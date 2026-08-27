<?php

declare(strict_types=1);

namespace Balin\Tabula\Schema;

/**
 * Alan tipleri — TEK sözlük.
 *
 * Mevcut ERP'de iki uyuşmayan tip sözlüğü vardı (exporter `integer|float|numeric|string|date|list`,
 * şema tarafı `string|int|float|bool|date`) ve ortak olmayan değerler sessizce metne düşüyordu.
 * Burada tek bir enum hem dışa aktarma, hem içe aktarma, hem şablon üretimi için geçerlidir.
 */
enum FieldType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Money = 'money';
    case Quantity = 'quantity';
    case Bool = 'bool';
    case Date = 'date';
    case DateTime = 'datetime';
    case Enum = 'enum';

    /** Sabit seçenek kümesi (şablonda açılır liste olur). */
    case Options = 'options';

    /** Sayısal tipler — varsayılan hizalama sağa, Excel'de gerçek sayı olarak yazılır. */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Integer, self::Decimal, self::Money, self::Quantity => true,
            default => false,
        };
    }

    /** Tarih taşıyan tipler. */
    public function isTemporal(): bool
    {
        return self::Date === $this || self::DateTime === $this;
    }

    /** Kullanıcıya kapalı bir seçenek kümesinden gelen tipler (şablonda doğrulama listesi alır). */
    public function isEnumerable(): bool
    {
        return self::Enum === $this || self::Options === $this || self::Bool === $this;
    }

    /** Bu tip için varsayılan hizalama. */
    public function defaultAlign(): Align
    {
        return match (true) {
            $this->isNumeric() => Align::Right,
            self::Bool === $this, $this->isTemporal() => Align::Center,
            default => Align::Left,
        };
    }
}
