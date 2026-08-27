<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

/**
 * Dosyadaki kolonların şemadaki alanlarla nasıl eşleştirileceği.
 *
 * MEVCUT ERP'NİN ÖLÜMCÜL KUSURU BURADAYDI: eşleştirme ÇEVRİLMİŞ BAŞLIK METNİYLE yapılıyordu,
 * yani "Müşteri Kodu" dizesi dosyanın gerçek kimliğiydi. Sonuçları:
 *  - Çeviri dosyasındaki tek bir kelime değişikliği, kullanıcıların elindeki tüm eski
 *    şablonları sessizce okunamaz hâle getiriyordu.
 *  - İngilizce başlıklı bir dosya Türkçe oturumda hiç eşleşmiyordu.
 *
 * Tabula'da kimlik ANAHTARDIR. Ürettiğimiz şablonun 1. satırı kanonik anahtarları taşır
 * (Excel'de gizlidir), 2. satır kullanıcının okuduğu çeviridir, veri 3. satırdan başlar.
 */
enum MatchStrategy: string
{
    /**
     * Varsayılan: anahtar satırı VARSA onu kullan, yoksa etikete düş.
     *
     * Bizim ürettiğimiz şablonlar kusursuz gidiş-dönüş yapar; kullanıcının elle hazırladığı
     * ya da başka bir sistemden gelen dosyalar da çalışmaya devam eder.
     */
    case Auto = 'auto';

    /** Yalnız anahtar satırıyla eşleş; yoksa hata ver. Makineden makineye akışlar için. */
    case Key = 'key';

    /** Yalnız çevrilmiş etiketle eşleş. Eski dosyalarla geriye dönük uyum için. */
    case Label = 'label';
}
