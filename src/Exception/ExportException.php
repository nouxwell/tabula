<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use Balin\Tabula\Format;
use RuntimeException;

/** Dışa aktarma akışı hatalı kurulduğunda fırlatılır. */
final class ExportException extends RuntimeException implements TabulaException
{
    public static function noSource(): self
    {
        return new self('Veri kaynağı verilmedi: önce ->from(...) çağırın.');
    }

    public static function unsupportedFormat(Format $format): self
    {
        return new self(sprintf(
            '"%s" biçimi için yazıcı yok. (PDF Faz 3 kapsamındadır; şimdilik Xlsx ve Csv desteklenir.)',
            $format->value,
        ));
    }

    public static function noOutput(): self
    {
        return new self('Dışa aktarma hiç dosya üretmedi.');
    }

    public static function noColumns(string $schema, Format $format): self
    {
        return new self(sprintf(
            '"%s" şemasında "%s" biçimi için yazılacak kolon kalmadı — alanların hepsi Field::only() ile bu biçimin dışında bırakılmış olabilir.',
            $schema,
            $format->value,
        ));
    }

    public static function unwritableTarget(string $path, string $reason): self
    {
        return new self(sprintf('"%s" hedefine yazılamıyor: %s', $path, $reason));
    }
}
