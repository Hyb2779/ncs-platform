# NCS Platform durum

Tarih: 26 Eylül 2026. Dal: `main`, `7bb368d`.

Kimlik, anahtar, parola ve bağlantı bilgisi bu dosyada yok.

## Son doğrulama

- Tam süit: 157 test geçti, 762 assertion.
- `lang:check`: kataloglar eşleşiyor (464 anahtar, tr/en/de/ar).
- `wallet:verify`: ok.
- `sport_limits` genişletme migration’ı uygulandı.
- Mevcut kupon seçimlerinde `kickoff_at` boş olan yok (5 seçim, 0 boş).

## Faz tablosu

| Faz | Konu | Durum |
|---|---|---|
| 1 | Laravel iskeleti, Redis, Tailwind, dört dil, `lang:check` | Tamam |
| 2 | Tek `users` tablosu, hiyerarşi, giriş, kapsamlı kullanıcı yönetimi | Tamam |
| 3 | Cüzdan, değişmez defter, `WalletService`, gece `wallet:verify` | Tamam |
| 4a | Oyuncu sitesi, sağlayıcıdan bağımsız casino, GoldPalace slot, 1GameX canlı casino | Tamam |
| 5 | Ayrı faz olarak tanımlanmadı | — |
| 6a | API-Football bütçesi, bülten tabloları, senkron, oturum kuponu (onaysız) | Tamam |
| 6a ek | Menü ayrımı (`/sport`, `/sport/live`, `/sport/results`), isim çevirisi, `CouponCalculator` | Tamam |
| 6b | Kupon onayı, cüzdan düşümü, panel kupon listesi ve iptal | Tamam |
| 6c | Otomatik sonuçlandırma, 48 saat kuralı, düzeltme borcu, elle skor | Tamam |
| 6c ek | `kickoff_at` anlık görüntüsü, live-sync, “Maç bitti”, oynama anı skoru | Tamam |
| Limit | Para birimi başına hiyerarşik spor ayarları, Kupon Sorgulama | Tamam (`7bb368d`). 6b’deki tek satırlık limit bununla değişti |
| 6d | Canlı oran ve canlı bahis | Açık |
| Panel A | Tasarım sistemi, mobil kabuk, Chart.js, Spor Ayarları bileşenleri | Tamam, onay bekliyor |
| Panel B | daily_stats yazarı, job’lar, backfill, demo geçmiş | Bu tur |
| Panel C–E | Owner/süperadmin paneli, liste taşıma | Onay bekliyor |

## 6c — sonuçlandırma

`sport:settle-check` 10 dakikada bir, `withoutOverlapping`. Aday: kupon ve seçim `pending`, seçim `kickoff_at` değeri şu andan en az 110 dakika eski. İptal veya iade kuponlar için istek gitmez. Fikstür id’leri 20’li `ids` gruplarıyla çekilir; bu iş kritik sayılır (günlük 6.500 yumuşak tavanı geçince de çalışır).

Skor kolonları `ht_*` (ilk yarı) ve `ft_*` (90 dakika, `score.fulltime`). Marketler 90 dakika skoruyla kapanır; ilk yarı marketi `ht_*` kullanır.

| Durum | Karar |
|---|---|
| FT, AET, PEN | Sonuçlandır, `ft_*` doluysa |
| PST, CANC, ABD, AWD, WO, TBD | `kickoff_at` + 48 saatte void |
| SUSP, INT | 48 saatte void |
| NS, 1H, HT, 2H, ET, BT, P, LIVE | `kickoff_at` + 6 saatten sonra otomatik karar yok; owner’a “Elle sonuçlandır” uyarısı |

`CouponSettler` kupon satırını kilitler. Tek kayıp bacak kuponu hemen kayıp yazar. Void bacaklar 1.00 sayılır. Ödeme anahtarı `coupon:{id}:settle:{revision}`, ters kayıt `coupon:{id}:reverse:{revision}`.

### kickoff_at ve 48 saat

Kupon onayında fikstürün başlangıcı seçime `kickoff_at` olarak yazılır. Aday sorgu ve 48 saat bu anlık görüntüden hesaplanır; fikstürün `starts_at` alanı ertelenince değişmez.

FT, AET veya PEN geldiğinde API `fixture.timestamp` değeri `played_at` olur. Bu an `kickoff_at` + 48 saatin içindeyse skorla sonuçlanır; dışındaysa skor ne olursa olsun seçimler void edilir. Elle skor formunda “maçın oynandığı tarih” zorunludur ve aynı pencere uygulanır.

### Düzeltme borcu

Yalnızca sonuç ters kaydı bakiyeyi eksiye indirdiğinde `settlement_overdraft_amount` açık kadar yazılır. Bakiye tabanı `-tutar`tır; `allow_negative` yalnızca owner cüzdanındadır. Bakiye sıfır veya üstüne çıkınca tutar sıfırlanır.

Eksi bakiyede üye kupon basamaz ve slot veya canlı casino açamaz. Bayi bu üyeden bakiye çekemez. Panelde “Düzeltme borcu” owner’a ve üst zincire, kendi ağacıyla sınırlı gösterilir.

### Elle düzeltme

İlk yarı ve 90 dakika skoru zorunlu. `score_source=manual` olan fikstürün üzerine otomatik senkron yazmaz.

## Live-sync ve “Maç bitti”

`sport:live-sync` 2 dakikada bir, `withoutOverlapping`. Kanal adı `live-sync`; günlük istek sayacında ayrı satır. Kritik değil: yumuşak tavan dolunca durur.

İzlenen fikstür: elle skorlanmamış, üzerinde `pending` kuponun `pending` seçimi var, `kickoff_at` gelmiş. Tek `live=all` çağrısı canlı satırları (durum, dakika, gol, sayısal ilk yarı) günceller.

Bu yanıtta olmayan ve bir önceki durumu canlı listede veya HT olan fikstürler tek `ids` isteğiyle çekilir (en fazla 20). FT, AET veya PEN ve her iki `ft_*` doluysa skor yazılır, `settled_at` set edilir ve `SettleFinishedFixtures` hemen çalışır (`dispatchSync`). Job’un kendi kilidi vardır: `sport:settle-finished`, 600 saniye expire, 30 saniye release. Zamanlanmış settle-check ile aynı kilidi paylaşmaz.

Seçim hâlâ `pending` ve fikstür FT, AET veya PEN ise kupon ekranı ve canlı JSON “Maç bitti, sonuç bekleniyor” der (dört dil). Seçim kapandıktan sonra maç sonu skoru gösterilir.

## Oynama anı

Her seçimde, onay anındaki canlı durum saklanır: `placed_status`, `placed_minute`, `placed_home`, `placed_away`. Maç henüz açılmamışsa durum `NS`, dakika ve skor boş kalır. Kuponlarım ve panel kupon detayı bu anlık görüntüyü kullanır. Üye kuponları 60 saniyede bir yalnızca kendi kuponlarının canlı JSON’uyla yenilenir.

## Hiyerarşik spor limitleri

Owner varsayılanı para birimi başına bir satırdır (`user_id` boş, `limit_key` = `owner:{currency}`). Süperadmin ve bayi kendi satırını yazar (`user:{id}`), para birimi miras alınır ve değiştirilemez. Kur dönüşümü yok.

Etkin değer zincirdeki en kısıtlayıcı olanıdır: owner varsayılanı, süperadmin, üst bayiler, kendisi (süperadmin veya bayi ise). Tavan türünde küçük olan, taban türünde büyük olan kazanır. `null` sınırsız demektir. `cash_out_enabled` zincirde AND’dir; bir yerde kapalıysa kapalıdır. Alt seviye üst tavanı aşamaz; sınırsız seçeneği yalnızca üst zincir de sınırsızsa açıktır. Kayıt ve varsayılana dönüş `activity_logs` içine `sport.limits.updated` ve `sport.limits.restored` olarak yazılır.

Kombine en az 2 seçim ayar değildir; sabit kuraldır. `cancel_minutes` varsayılan 0 (oyuncu iptali kapalı). `null` süresiz pencere demektir; küçük değer daha kısıtlayıcıdır.

Üye toplamları (`max_stake_per_fixture`, `max_stake_per_outcome`, `repeat_limit_single`, `repeat_limit_combo`, `daily_max`) global değildir. Cüzdan satırı `FOR UPDATE` ile kilitlendikten sonra, aynı işlemde kontrol edilir. Aynı üyeden paralel iki kupon birlikte toplamı aşarsa biri reddedilir.

Tavanı aşan oran bültende kilitli gösterilir. Kupon onayında sunucu, marj uygulanmış fiyatı yeniden kontrol eder. Hata metni hangi limitin aşıldığını söyler (dört dil).

Panel “Spor Ayarları”: Kupon, Bahis Limitleri, Kazanç Limitleri, Oran Koruması. Alan başına Sınırsız kutusu ve miras alınan tavan veya taban. Owner üç para birimini ayrı kaydeder. Bayi ve süperadmin ekranı açabilir.

`live_close_minute` hiyerarşide durur; kupon onayında henüz uygulanmaz. `max_stake_live` ve canlı kazanç tavanları, seçim fikstürü canlı veya HT ise guard’da vardır. Yerleştirme hâlâ açık olmayan fikstürü reddettiği için bu kontroller canlı bahis (6d) gelene kadar fiilen işlemez. Bahis bozdurma yalnızca bir bayraktır; formül ve akış yok.

### Alanlar

Kupon: `cash_out_enabled`, `cancel_minutes`, `live_close_minute`, `max_selections`, `min_coupon_odds`, `max_coupon_odds`.

Bahis: `min_stake`, `max_stake_general`, `max_stake_single`, `max_stake_live`, `max_stake_per_fixture`, `max_stake_per_outcome`, `repeat_limit_single`, `repeat_limit_combo`, `daily_max`.

Kazanç: `max_payout_general`, `max_payout_single`, `max_payout_live`, `max_payout_live_single`.

Oran: `min_odds_prematch`, `max_odds_prematch`, `min_odds_live`, `max_odds_live`.

Taban (büyük olan kazanır): `min_stake`, `min_coupon_odds`, `min_odds_prematch`, `min_odds_live`. Diğer sayısal alanlar tavan (küçük olan kazanır).

### Owner varsayılanları

Üç para biriminde ortak: min kupon oranı 1.01, max kupon oranı 500, min oran 1.01, max oran 30 / 30, max seçim 20, canlı kapanış dakikası 85, bahis bozdurma kapalı, iptal penceresi 0.

| Alan | TRY | USD | EUR |
|---|---:|---:|---:|
| min_stake | 1 | 1 | 1 |
| max_stake_general / single | 10000 | 250 | 200 |
| max_stake_live | 10000 | 100 | 100 |
| max_stake_per_fixture | 10000 | 500 | 400 |
| max_stake_per_outcome | 10000 | 250 | 200 |
| repeat_limit_single / combo | 10000 | 250 | 200 |
| daily_max | 50000 | 1250 | 1000 |
| max_payout_general / single | 100000 | 2500 | 2000 |
| max_payout_live / live_single | 100000 | 1000 | 800 |

## Kupon Sorgulama

Panelde sayısal kupon id’si ile arama. Boş sorgu formu gösterir. Kayıt yoksa veya kupon görüntüleyenin ağacında değilse 404. Kayıt bulunursa kupon detayına gider.

## Günlük istatistik kuralları

Yazar `DailyStatWriter`. `sport:stats-refresh` bugün ve dünü 10 dakikada bir yazar. `sport:stats-close` her gece iki gün önceyi kapatır. `sport:stats-backfill` aynı yazarı kullanır; dünden eski günleri kapatır. Kapalı güne düşen düzeltme o günü yeniden hesaplar. Grafik eksi değeri kesmez.

- `product` değeri `sport`, `slot` veya `live_casino` olan adjustment kayıtları payout’a işaretiyle girer. Bunlar sonuç düzeltmesinin ters kaydı ve sağlayıcı rollback’idir. Kazanan kupon sonradan kayba düzeltilirse o günün GGR’si ödeme hiç yapılmamış gibi çıkar.
- `product` değeri `transfer`, `adjustment` veya `bonus` olan kayıtlar ciroya ve payout’a hiç girmez.
- İade ve iptal, hareketin gerçekleştiği güne yazılır (süperadmin saat dilimi). O günün cirosu eksi olabilir.
- Job, her süperadminin kendi saat diliminde bugün ve dünü 10 dakikada bir yeniden yazar. Gece job’u iki gün önceyi kapatır. Kapalı güne düşen düzeltme o günü yeniden hesaplar. Backfill aynı kod yolunu kullanır. Tümü idempotent.
- Faz E’ye Ligler, Sağlayıcılar/Oyunlar ve Marjlar da girer. Marjlar owner menüsündedir.

## Açık işler

- Faz 6d: canlı oranlar ve canlı bahis. `/sport/live` şimdilik “Canlı oranlar yakında” der; API’ye ek canlı oran isteği atmaz.
- `live_close_minute` kaydı var, yerleştirmede uygulanmıyor.
- Bahis bozdurma bayrağı var; hesap ve akış yok.
- Anında sonuçlandırma job’u kilit doluysa atlanır. Fikstür artık canlı listede olmadığı için sonraki live-sync aynı maçı yeniden kuyruğa koymaz; kupon 110 dakikalık settle-check’i bekler.
- Aynı saniyede iki defter satırı `wallet:verify` sırasında sıra bağımlılığı yaratabilir. Son çalıştırma temiz geçti.
