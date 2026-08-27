<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use LogicException;

/**
 * Yazıcı yaşam döngüsü sırası bozulduğunda fırlatılır.
 *
 * `\LogicException` soyundan gelir çünkü bunlar veri ya da ortam hatası değil,
 * çağıran koddaki sıra hatasıdır: open → startSheet → writeRow* → finishSheet → close.
 * Mevcut ERP'de yazıcıların böyle bir sözleşmesi yoktu; yanlış sırada çağrılan bir
 * yazıcı ya sessizce boş dosya üretiyor ya da anlaşılmaz bir "null" hatasıyla patlıyordu.
 */
final class WriterException extends LogicException implements TabulaException
{
    public static function notOpened(): self
    {
        return new self('Yazıcı açık değil: önce open(...) çağırın.');
    }

    public static function noActiveSheet(): self
    {
        return new self('Aktif sayfa yok: önce startSheet(...) çağırın.');
    }

    public static function alreadyOpen(): self
    {
        return new self('Yazıcı zaten açık: yeniden open(...) çağırmadan önce close() çağırın.');
    }
}
