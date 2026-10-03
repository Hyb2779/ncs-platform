# NCS Platform — Sistem ve Durum Dosyası

Güncelleme: 25.09.2026
Bu dosya yeni sohbete başlarken bağlam olarak verilir. Gizli bilgi (token, şifre, API anahtarı) içermez; hepsi sunucudaki `.env` dosyasında.

---

## 1. Genel

- **Ürün:** Kapalı devre bahis platformu (spor + slot + canlı casino), bayi tabanlı.
- **Diller:** TR, EN, DE, AR (Arapça RTL). **Arapça ana hedef pazar**, özellikle sporda.
- **Marka adı:** Henüz belirlenmedi. `APP_NAME` = "Platform", tek yardımcıdan okunuyor (`brand()->name()`); ileride her süperadmin kendi marka adı/logosu/renginin (`--accent`) ayarını yapacak.
- **Domain / SSL:** Henüz yok. Şu an `http://91.208.197.142` üzerinden test ediliyor.

## 2. Altyapı

| Konu | Değer |
|---|---|
| Sunucu | 91.208.197.142 (AlexHost VPS, 4 çekirdek / 8 GB / 80 GB), hostname `kapalidevre`, SSH alias `winoxbet` |
| OS | Debian 13 |
| Stack | Nginx, PHP 8.4-FPM, MariaDB (tüm tablolar InnoDB, config'de açıkça `InnoDB`), Redis, Node/Vite |
| Uygulama | Laravel 13, Blade + Tailwind + Alpine |
| Proje dizini | `/var/www/platform` (nginx site adı `platform`) |
| Repo | `git@github.com:Hyb2779/ncs-platform.git` (private, deploy key "kapalidevre-sunucu", read/write) |
| Veritabanı | `ncsvip`, kullanıcı `ncsvip`@localhost (bilgiler `/root/.ncsvip_db_credentials` ve `.env`) |
| Referans kod | `/root/reference/ncs-vip/` (NCS VIP'in çalışan GoldPalace entegrasyonu), `/root/reference/design/` (onaylı tasarım taslağı `Main.dc.html`, `Mobil.dc.html`) |

### Çalışma kuralları
- Cursor ile çalışılıyor; kurallar `.cursor/rules/proje.mdc` içinde (alwaysApply).
- Artisan komutları **her zaman** `sudo -u www-data php artisan ...` ile. Scheduler cron'u `www-data` kullanıcısında.
- Her anlamlı adımda ayrı commit + push.
- Gizli bilgiler sadece `.env`'de; koda, loga, commit'e girmez.
- SSH şifreli giriş kapalı, sadece anahtar.

## 3. Mimari kurallar (değişmez)

### i18n
- Arayüz çevirileri sadece `lang/{tr,en,de,ar}` dosyalarında; veritabanı overlay'i yok.
- Hardcoded metin yasak; her metin `__()` ile.
- `php artisan lang:check` dört dili karşılaştırır; git pre-commit hook eksik anahtarda commit'i engeller.
- Tailwind'de sadece mantıksal yön sınıfları (`ms/me/ps/pe/start/end`), RTL otomatik.
- Fontlar: gövde Manrope, rakamlar Barlow Condensed, Arapçada Cairo.
- Arapçada oranlar ve tutarlar batı rakamlarıyla (1.85), tarih/ay adları Arapça.

### Hiyerarşi
- Tek `users` tablosu: `role` (owner, superadmin, bayi, uye), `parent_id`, `path` (materialized path), `depth`, `superadmin_id`.
- Owner sadece süperadmin, süperadmin sadece bayi, bayi sadece üye oluşturur.
- **Dil, para birimi, saat dilimi süperadminde sabitlenir**, altındakiler miras alır, değiştiremez. Kur dönüşümü yok.
- Herkes **kendisini ve alt ağacını** görür; **üst zinciri asla** (ad ve bakiye gizli, sadece "Üst hesap" etiketi), **yatay dalları asla**. Başka dala ID ile erişim 404.
- `user_limit` (açabileceği alt kullanıcı sayısı), `commission_rate` (üstüyle anlaşma oranı), `status` (active/passive/banned; üst zincirden biri pasifse giriş yok).
- `activity_logs` tüm yönetim işlemlerini kaydeder.

### Cüzdan
- `wallets` (user_id + currency unique, `allow_negative` sadece owner cüzdanlarında) ve değişmez `wallet_transactions` (balance_before/amount/balance_after, type, product: sport/slot/live_casino/transfer/bonus/adjustment, `idempotency_key` UNIQUE).
- `wallet_transactions` UPDATE/DELETE veritabanı trigger'ı ile yasak; düzeltme ters kayıtla.
- Bakiye **sadece WalletService** üzerinden değişir (transaction + SELECT FOR UPDATE, iki cüzdanda id sırasına göre kilit).
- **Owner kredi modeli:** Kredi üretme (mint) yok. Owner süperadmine doğrudan yükler, owner cüzdanı eksiye düşer; ekranda "Dağıtılan kredi" olarak pozitif gösterilir. Owner'ın TRY/USD/EUR için ayrı cüzdanı var, alt kullanıcının para birimine göre seçilir.
- Bakiye ekle/çıkar sadece doğrudan alt kullanıcıya; çocuk bakiyesinden fazla çekilemez.
- `php artisan wallet:verify` her gece 03:30 UTC (toplam = bakiye, kayıt zinciri tutarlı).
- Hesap hareketleri ekranı: her transfer tek satır, "konu hesap" kuralı (üstle yapılan transferde konu hesap giriş yapanın kendisi, alt ağaçta çocuk hesap), önceki + tutar = sonraki her zaman tutar.
- Mutabakat (süperadminle kim kime ne ödeyecek) ayrı bir rapor olarak Faz 5'te yapılacak.

## 4. Sağlayıcılar

| Sağlayıcı | Kullanım | Durum |
|---|---|---|
| **GoldSlotPalace** (agent `winoxbet_try`) | Slot | Çalışıyor. Callback: `http://91.208.197.142/api/casino/goldpalace/callback`. Sözleşme NCS VIP'teki çalışan koddan alındı (`command` + `data.account/trans_guid/amount`, cevap `{result:0,status:"OK",data:{balance}}`, metin hata kodları). Dil kodları: TR 7, EN 1, DE 10, AR 15. 2.362 oyun senkron. |
| **1GameX** | Canlı casino | Beklemede. API base URL ve resmi callback/imza dokümanı gerekiyor (`/root/reference/onegamex-docs.txt` olarak eklenecek). Callback: `/api/casino/onegamex/callback`. |
| **API-Football** (Pro, günde 7.500 istek) | Spor verisi | Çalışıyor. Bookmaker id 8 (Bet365). |
| **Anthropic API** | Takım/lig adı çevirisi | `ANTHROPIC_API_KEY` henüz eklenmedi (kredi yüklenecek). |

- Sağlayıcıya giden kullanıcı kodu: `np_` + user id (eski WinoxBet oyuncularıyla karışmaması için).
- Casino altyapısı sağlayıcıdan bağımsız: `CasinoProvider` interface, tek callback rotası `/api/casino/{provider}/callback`, idempotency `provider:transaction_id`. Demo sağlayıcı sadece production dışında aktif.
- **Güvenlik notu:** GoldPalace token'ları, 1GameX secret ve API-Football anahtarı bir sohbette ve ekran görüntülerinde açık paylaşıldı; canlıya çıkmadan önce yenilenmeli (Reissue / Reset). 1GameX token şifresi zayıf, değiştirilmeli.

## 5. Spor (API-Football)

- Tablolar: sport_countries, sport_leagues, sport_teams, sport_fixtures, sport_markets, sport_odds, sport_translations, coupons, coupon_selections, sport_limits.
- Marketler: Maç Sonucu (bet 1), Çifte Şans (12), 1.5/2.5/3.5 Alt-Üst (5), Karşılıklı Gol (8), İlk Yarı Sonucu (13). Seçenekler **outcome kodu** ile çalışır, kanonik sıra (1-X-2, 1X-12-X2, Alt-Üst, Var-Yok).
- Marj motoru: global → lig → maç → market (superadmin override alanı var), 2 ondalık, min oran 1.01.
- İstek bütçesi: tüm çağrılar `ApiFootballClient` üzerinden, Redis sayaç; 6.500'ü geçince sadece kritik işler. Owner panelinde "Spor Veri Durumu".
- Senkron: ligler günde 1, fikstür 3 saatte bir, oranlar 3 saatte bir (2 saatten az kalanlar 30 dk), sonuçlar günde 4 kez.
- Takım/lig/ülke adları `sport_name()` ile kullanıcının dilinden; çeviri yoksa İngilizce. Otomatik çeviri (Claude API) anahtar gelince çalışacak; `source=manual` kayıtlar ezilmez; owner panelinde "Spor Çevirileri".
- **CouponCalculator:** toplam oran = oranların çarpımı, 2 ondalığa yuvarlanır; olası kazanç = tutar × yuvarlanmış toplam oran. Arayüz ve sunucu aynı sınıfı kullanır.
- Kupon onayı: oranlar sunucudaki güncel değerden, tek transaction, idempotency, tekli modda her seçim ayrı kupon, limit kontrolleri, "oran değişirse kabul et", bayi ve üstleri gerekçeyle iptal edip iade edebilir, oyuncu iptali varsayılan kapalı.
- Menü: Spor `/sport`, Canlı Bahis `/sport/live`, Slot `/slots`, Canlı Casino `/live-casino`, Sonuçlar `/sport/results`.

## 6. Tasarım

- Oyuncu sitesi koyu tema: zemin #0E1117, panel #151A23, ikincil #1B2230, çizgi #232B39, metin #E8ECF3, ikincil metin #9AA4B5, vurgu `--accent` #F5B83D.
- Masaüstü spor: 3 kolon (sol spor/lig menüsü, orta yoğun bülten, sağ sabit kupon), canlı skor şeridi, market filtreleri. Referans: Betgol (bülten/kupon/rapor/ayarlar) + Lanusbet (modern vitrin, ayrı back-office, dashboard).
- Mobil: maç kartları, alt menü (ikonlu, aktif vurgulu), alttan açılan kupon, "Kuponu aç" çubuğu.
- Yönetim paneli: açık tema back-office, sol menü, masaüstünde tablo, mobilde kart.
- Üye dil seçici göremez (sadece giriş sayfasında, giriş yapmamış ziyaretçiye).

## 7. Faz durumu

| Faz | İçerik | Durum |
|---|---|---|
| 1 | İskelet, i18n, lang:check, kurallar | ✅ |
| 2 | Hiyerarşi, giriş, panel, üst zincir gizliliği | ✅ |
| 3 | Cüzdan, defter, owner kredi modeli, wallet:verify | ✅ |
| 4a | Oyuncu sitesi, casino altyapısı, GoldPalace | ✅ (1GameX beklemede) |
| 6a | Spor verisi, bülten, marj, kupon arayüzü, çeviri katmanı, menü | ✅ |
| 6b | Kupon onayı, limitler, iptal, Kuponlarım, panel kupon listeleri | ⚠️ Kod ve testler tamam (82/82), **ama elle kupon oynanamıyor** (butona basınca bir şey olmuyor, hata yok) |
| 6c | Otomatik sonuçlandırma (bitmesi gereken maçları 10-15 dk'da bir kontrol; NCS VIP ncs-sports settlement mantığı referans) | Sırada |
| 6d | Canlı bahis (canlı skor, canlı oran, askıya alma) | Sonra |
| 5 | Raporlar: rapor detay, günlük, komisyon/net, süperadmin mutabakatı | Sonra |

## 8. Açık işler (öncelik sırasıyla)

1. **Kupon onayı çalışmıyor:** Muhtemelen idempotency anahtarı `crypto.randomUUID()` ile üretiliyor ve HTTP'de (SSL yok) çalışmıyor; daha önce bakiye formunda aynı sessiz hata olmuştu. Anahtar sunucu tarafında üretilmeli, tüm hatalar ekranda gösterilmeli. Cursor'a teşhis prompt'u verildi.
2. **Deadlock yeniden denemesi:** Şu an sadece paralel testin alt sürecinde. WalletService içine taşınmalı (1213 ve 1205'te en fazla 3 deneme, idempotency ile çift kayıt yok).
3. Elle kupon testi: oyna → Kuponlarım → bayi iptal eder → iade.
4. Faz 6c: otomatik sonuçlandırma.
5. GoldPalace ilk testindeki 10 ₺ bahsin sağlayıcı tarafında oynanıp oynanmadığı teyidi (oyun krediyi 0 gördüğü oturum).
6. 1GameX dokümanı ve entegrasyon.
7. Arapça kontrol turu (`demo-ar` hesabı): RTL, takım/lig adları, market terimleri (Arapça bilen biri gözden geçirmeli), rakamlar, tarih, kupon mesajları, mobil.
8. `ANTHROPIC_API_KEY` eklenmesi ve toplu çeviri.
9. Oyuncu sitesi genel tasarım geçişi (ortak bileşenler, banner, oyun kartları, Hesabım tablo başlıkları ve açıklamalar).
10. Canlıya çıkış öncesi: domain + SSL, owner şifresi değişimi, `APP_DEBUG=false`, `APP_ENV=production` (demo sağlayıcı ve DemoSeeder otomatik kapanır), sağlayıcı anahtarlarının yenilenmesi, callback adreslerinin domain'e taşınması.

## 9. Test hesapları

| Hesap | Rol | Dil / Para | Şifre |
|---|---|---|---|
| owner | Owner | tr / TRY | `.env` → `OWNER_PASSWORD` |
| demo-tr, demo-de, demo-us, demo-ar | Süperadmin | tr/TRY, de/EUR, en/USD, ar/USD | password |
| demo-tr-bayi-1, demo-tr-bayi-2 (diğer ağaçlarda da benzer) | Bayi | süperadmininden | password |
| demo-tr-uye-1-1 … (her bayinin altında 3 üye) | Üye | süperadmininden | password |

Demo hesaplar sadece test içindir; DemoSeeder production'da çalışmaz.

## 10. Son test sonuçları

- Tam test süiti: 82 geçti (465 assertion)
- `lang:check`: 369 anahtar eşleşiyor
- `wallet:verify`: ok
- API-Football ilk senkron: 10 lig, 34 maç, 348 oran, 105 istek
