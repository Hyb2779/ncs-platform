# NCS Platform — Sistem ve Durum Dosyası

Güncelleme: 02.10.2026 gece — canlıda (açılış öncesi test hesapları var). Bugün: **Oyun Yönetimi (Faz 1)** bitti ve canlıda (`a4243bd`): `game_blocks` ile Owner genel + süperadmin ağacı bazında oyun aç/kapat; launch testleri iframe oyun ekranına uyarlandı (212 test). Blackeagle/Volkan isteklerine göre **Faz 2–7 planı** çıkarıldı. **Faz 2 ("Siteyi göster" kaldırıldı) tamam** (`4380d5b`); sıradaki **Faz 3**. **Bölüm 0d, 0c, 0b ve 0 bu dosyanın geri kalanından önce gelir; çelişen yerde en yeni bölüm geçerlidir.**
Bu dosya yeni sohbete başlarken bağlam olarak verilir. Gizli bilgi (token, şifre, API anahtarı) içermez; hepsi sunucudaki `.env` dosyasında.

---

## 0d. 02.10.2026 güncellemesi — önce bunu oku (0c, 0b ve 0'dan da yeni)

### Çalışma şekli
- İş Yusuf'la **sohbette adım adım** yapılır (her seferinde tek komut, çıktı görülür, sonra sıradaki). Başka bir kod ajanına (Claude Code, Cursor) görev metni yazılıp devredilmez.

### Oyun Yönetimi (Faz 1) — TAMAM, canlıda (`a4243bd`)
- **Sorun:** Eski `/panel/casino/games` sadece Owner'da, sadece ilk 100 oyun, arama/filtre yok; `updateGame` doğrudan `casino_games.is_active` yazıyordu. `is_active` **senkronun alanı** (RomaSpin 01:30 UTC gece senkronu, GoldPalace senkronu, GoldPalace öncelik harmanlaması yeniden hesaplar) → admin kapatması kalıcı değildi.
- **Çözüm:** Ayrı engel katmanı. Senkron bu katmana hiç dokunmaz.
  - **Tablo `game_blocks`** (migration `2026_10_02_000001`): `superadmin_id` (NULL = Owner'ın genel engeli, dolu = o süperadminin ağacı), `scope` (`provider` | `vendor` | `category` | `game`), `value` (provider code / vendor slug / `slot|live|mini` / `casino_games.id`), `created_by`, timestamps. FK'ler `users`'a RESTRICT. NULL'lı unique MariaDB'de çakışmayı engellemediği için tekillik controller'da (işlem yapanın `users` satırı `lockForUpdate`) sağlanır.
  - **Model** `App\Models\GameBlock`.
  - **Servis `App\Services\Casino\GameAvailability`** (tek karar noktası):
    - `superadminIdFor(?User)`: ziyaretçi/owner → null (sadece genel), süperadmin → kendisi, bayi/üye → `superadmin_id`.
    - `blocked(?int)`: genel + süperadmin engelleri, Redis önbellek (600 sn) + sürüm anahtarı `casino:game_blocks:version`; `flush()` sürümü artırır (anında yansır).
    - `apply(Builder, ?User)`: sorguya engelleri ekler. **Kategori kuralı lobiyle aynı:** `live` = `is_live`; `mini` = `category='mini'`; `slot` = canlı ve mini/virtual olmayan her şey (NULL dahil).
    - `isPlayable(CasinoGame, ?User)`, `categoryOf(CasinoGame)`.
  - **Uygulanan yerler:** `SiteController::games($mode)` (tüm lobi listeleri, vendor menüsü sayıları, kategoriler, popüler, favoriler, son oynananlar, ana sayfa "günün oyunları" + popüler slot şeridi — hepsi buradan geçer); `GameLauncher::open()` (engelli oyun → 404, link bilinse bile); `HomeFeed::build()` hızlı erişim sayaçları (`slots`, `casino`, `$viewer` ile).
  - **Casino callback'leri ENGELLENMEZ:** açık tur biter, kazanç/iade yazılır. Engelli oyuna sadece yeni giriş yapılamaz.
- **Panel "Oyun Yönetimi"** — `GET /panel/games` (`panel.games.index`), `POST /panel/games/block` (`panel.games.block`), `App\Http\Controllers\Panel\GameControlController`:
  - Owner + Süperadmin; **Bayi 404** (menüde yok). Menü öğesi Owner/Süperadmin bloğunda Riskli Kuponlar'ın altında (`PanelMenu`), eski Owner'a özel "Oyunlar" öğesi kaldırıldı.
  - Ekran (`resources/views/panel/games/index.blade.php` + `_toggle.blade.php`): rol notu; Sağlayıcılar (aktif ve aktif oyunu olanlar; 1GameX görünmez), Kategoriler (sayılarla), Oyun markaları (katlanır kutu, `Vendors::name()`), Oyunlar (50'şer sayfalama, ad araması, kategori/marka/durum filtresi, çoklu seçim + "Seçilenleri kapat/aç", satırda aç/kapat; Owner'da satırda popüler + sıra).
  - Süperadmin, Owner'ın kapattığını **"Yönetim tarafından kapalı"** görür, butonu pasif; kaldırma isteği sadece kendi kayıtlarını siler (Owner engeli kalır).
  - `toggle`: tek veya toplu (≤200), değerler doğrulanır (olmayan vendor/oyun → 422), idempotent, sonra `flush()` + `activity_logs` (`casino.games_blocked` / `casino.games_unblocked`, payload: scope, values (ilk 50), count, superadmin_id).
  - **Oyun satırındaki durum sadece `game` kapsamındaki engeli gösterir**; marka/kategori/sağlayıcı engeli satıra yansımaz (istenirse iyileştirme).
- **Eski ekran:** `GET /panel/casino/games` → yeni sayfaya yönlendirme (`CasinoController::games(): RedirectResponse`). `PUT /panel/casino/games/{game}` (popüler/sıra) duruyor ama **artık `is_active` yazmıyor** (eski kod formda `is_active` gelmezse oyunu kapatıyordu — popüler/sıra yeni sayfaya taşınınca her kayıtta oyunu kapatırdı; düzeltildi).
- **Çeviri:** `lang/{tr,en,de,ar}/panel.php`'ye 25 anahtar (`games_*`), `lang:check` **613 anahtar**. AR "Mini Oyunlar" = `ألعاب سريعة` (sitedekiyle aynı).
- **Testler:** `GameBlockTest` (11): Owner engeli her yerde + launch 404, süperadmin engeli sadece kendi ağacı, süperadmin Owner engelini açamaz, kategori/sağlayıcı/marka, **senkron `is_active=true` yazsa da engel sürer**, bayi 404, Owner/süperadmin sayfayı görür, geçersiz değer 422, log. Testte zincir: Owner → `HierarchyService::create()` (rol işlemi yapana göre: owner→süperadmin→bayi→üye).
- **`70feedb` (01.10, oyun ekranı iframe + Geri Dön) 3 testi kırmıştı:** launch artık `view('site.play')` (200) döndürüyor; `CasinoTest` + `GoldPalaceTest`'teki `assertRedirect('<oyun adresi>')` → `assertOk()->assertSee('<oyun adresi>', false)`. **212 test geçti.**
- **Durum dosyası:** Sunucudaki `ncs-platform-durum.md` 02.10'da 25.09 sürümünde kalmıştı; güncel sürüm Yusuf'un bilgisayarından `scp` ile atıldı. Dosya güncellenince sunucuya da atılıp commit'lenmeli.
- **Not:** `GoLiveCommand`'ın sıfırlama listesinde `game_blocks` yok; go-live bir daha çalıştırılacaksa eklenmeli (FK `users`'a RESTRICT).

### Ekip istekleri (Blackeagle + Volkan, 01–02.10) ve faz planı
- **Referans:** Volkan'ın kullandığı **panel91.com** ("Sports Admin" 2.0.0.33): Manager → Dealer → Shop → User + her seviyede **Back Office Users** (personel); personel başına izin anahtarları (Access Log, Actions Log, Add Credits, Add Shop, Add User, Cancel Ticket, Casino Reports…); kullanıcı detay sekmeleri (Betting, Transactions, Tickets, Activity, Deposits, Withdrawals, Access Log, Notes, Allowed Providers…); menüde Risk Management, Casino Transactions, Reports, User Notes, Actions Log. Karşılık: Manager≈Süperadmin, Dealer≈Bayi; **Shop seviyesi Wegas'ta yok (karar Yusuf'ta)**.
- **Alınmayacaklar** (açık online casino özellikleri, kapalı devrede karşılığı yok): Self Exclusions, Verifications, Bonuses, Payment Accounts, Affiliate Links.
- **Volkan'ın şikayetleri:** sistemden **kullanıcı açamıyor** (Faz 3'te teşhis); hiyerarşi ekranında herkese "oyuncu" diyor, bayi/shop/oyuncu ayrımı belli değil (Faz 4); rapor, işlem geçmişi ve log "olmazsa olmaz".
- **Blackeagle'ın sırası:** önce oyun aç/kapat (✅), "Siteyi göster" kalksın, admin açarken saat dilimi kalksın ve komisyon zorunlu olmasın, Kullanıcılar/Bayiler iki ayrı sekme; **"en önemlisi rapor detay"** + log (site güvenliği için şart).

| Faz | İçerik | Durum |
|---|---|---|
| 1 | Oyun aç/kapat (`game_blocks`) | ✅ `a4243bd` |
| 2 | "Siteyi göster" kaldırıldı: `layouts/panel.blade.php`'deki tek buton (mobilde ikon olarak görünen de aynısı) + `view_site` çevirisi (4 dil). `Domains::siteUrl()` artık çağrılmıyor ama duruyor. `DomainSeparationTest` panelde site linki **olmamasını** doğrular. | ✅ `4380d5b` |
| 3 | **Admin/bayi açma formu:** saat dilimi alanı formdan kalkar, kayıtta otomatik `Europe/Istanbul` (**kolon kalır**: `daily_stats.stat_date` süperadminin saat dilimine bağlı); komisyon zorunlu değil (Owner→Süperadmin ve Süperadmin→Bayi; boş = 0, raporlar boşu 0 sayar). Aynı akışta Volkan'ın "kullanıcı açamıyorum" hatası loglardan teşhis + test. İlgili: `_fields.blade.php`, `StoreUserRequest`, `UpdateUserRequest`, `HierarchyService::create/inheritedLocale`. | **SIRADAKİ** |
| 4 | **Kullanıcılar / Bayiler sekmeleri:** Kullanıcılar = sadece üye (mevcut filtre + bakiye paneli); Bayiler = Owner için süperadmin + bayiler, süperadmin için kendi bayileri (bayi rolünde sekme yok): bakiye, üye sayısı, dönem cirosu, durum; satırdan **Bayi Hareketleri** (mevcut hesap hareketleri o bayiye filtreli; ayrı "Bayi hareketleri" menüsü buraya taşınır). Rol etiketleri netleşir. | Sırada |
| 5 | **Rapor detay + loglar (Blackeagle: en önemlisi):** oyuncu/bayi hareketlerinde açıklama şu an "—" (`423,75 ₺ −12,00 ₺ …`); her satırda **işlem türü** (spor kuponu / casino bahis / casino kazanç / iade / yükleme / çekim / düzeltme), **referans** (kupon no veya oyun adı + round), **işlemi yapan** (hangi admin/bayi). **İşlem logu ekranı** (`activity_logs` zaten tüm yönetim işlemlerini yazıyor, ekran yok). **Giriş logu** (kim, ne zaman, IP, cihaz; tablo var mı kontrol). | Sırada |
| 6 | Raporlar: dönem seçmeli, bayi/üye bazında spor/casino ve sağlayıcı kırılımlı ciro/kazanç/GGR | Sonra |
| 7 | Personel (back office) hesapları + izin anahtarları (rol varsayılanları + anahtarlar); süperadmin ağacında izinli sağlayıcılar (Faz 1 altyapısıyla) | Sonra |

- Bayiye yeni yetki/ekran eklemeden önce Yusuf'a sorulur (Bölüm 3 kuralı geçerli).

---

## 0c. 30.09.2026 güncellemesi — önce bunu oku (0b ve 0'dan da yeni)

### RomaSpin (yeni sağlayıcı)
- **Protokol OroPlay ile birebir aynı** (white-label). RomaSpin tüm oyunlarının (canlı casino dahil) klon olduğunu açıkça söyledi. Doküman sürümü 2.15.7; dokümandaki `romaspin-api-endpoint` yer tutucu, **gerçek API: `https://ronwcesgkb.com/api/v2`**.
- **Anlaşma:** Tüm sağlayıcılar **GGR %3,5**, **haftalık postpaid** (fatura her pazartesi, sonra ödeme; ön ödeme yok). Trafik iyi giderse 1 ay sonra oran yeniden görüşülecek.
- **Hesaplar:** Ana hesap `master-NCS` (**production**, USD, GGR %3,5). Wegas için alt agent **`wegas`**: Operatör, API modu Seamless (panelde "Sorunsuz"), **TRY**, GGR %3,5, callback `https://wegas11.com/api/casino/romaspin`. RomaSpin `wegas` için IP'leri whitelist'e ekledi (91.208.197.142, 91.229.239.212). Saat dilimi panelde UTC+8 idi, Istanbul'a çekilecekti (kontrol et).
- **`.env`:** `ROMASPIN_API_URL`, `ROMASPIN_CLIENT_ID=wegas`, `ROMASPIN_CLIENT_SECRET`, `ROMASPIN_SLOT_VENDORS` (aşağıda). Yedek: `/root/bak/env_pre_romaspin_*`. `config/casino.php` → `romaspin`: url, client_id, client_secret, `currency` (TRY), `slot_vendors`, `overlap_vendors`.
- **Vendor tipleri (`/vendors/list`):** 1 = canlı casino, 2 = slot, 3 = mini oyun, 6 = poker (alınmıyor).
- **`App\Services\Casino\RomaSpinProvider`:**
  - **Token:** `POST /auth/createtoken` (clientId + clientSecret). Limit **30 sn'de 5**, fazlası hesabı bloklayabilir. Redis `casino:romaspin:token`, `expiration − 300 sn` TTL, kilitli (aynı anda tek istek), 401 gelirse bir kez yenileyip tekrar dener. Token ~16,5 saat geçerli.
  - **Senkron:** Tip 1 → `category=live`, `is_live=true`; tip 3 → `category=mini`; tip 2 → `category=slot`, sadece `slot_vendors` listesindekiler veya `overlap_vendors`'takiler. `external_id = vendorCode|gameCode` (`lobby` kodu sağlayıcılar arasında tekrar eder). `lobby` kayıtları "<Sağlayıcı> Lobby" adıyla (Dream Gaming ve SA Gaming sadece lobi verir). Cevap vermeyen sağlayıcının oyunlarına dokunulmaz, listeden düşenler pasif.
  - **GoldPalace önceliği (harmanlama):** `overlap_vendors` = ortak 11 sağlayıcı: `slot-pragmatic→pp, slot-pgsoft→pg, slot-habanero→hab, slot-booongo→bng, slot-hacksaw→hacksaw, slot-cq9→cq9, slot-3oaks→3oaks, slot-jili→jili, slot-tada→tada, slot-egt→egt, slot-amusnet→amusnet`. Bu sağlayıcıların RomaSpin oyunları **GoldPalace etiketiyle** kaydedilir (sol menüde tek sağlayıcı). Aynı etikette GoldPalace'ta **aktif** ve aynı normalize adlı oyun varsa RomaSpin kopyası **pasif**. Normalize: küçük harf, ™/® silinir, `&`→and, Roma rakamı IV/III/II→4/3/2, bilinen yazım hatası `chrismas→christmas`, harf/rakam dışı her şey silinir. **"deluxe" korunur** (Book of Ra ≠ Book of Ra deluxe).
  - **`ROMASPIN_SLOT_VENDORS`** (GoldPalace'ta hiç olmayan 20 sağlayıcı): novomatic, amatic, rubyplay, dreamtech, popok, ka, jdb, nolimitcity, evoplay, fachai, wazdan, playson, wg, micro, smartsoft, yellowbat, amigo, popiplay, mascot, atg (hepsi `slot-` önekli). Sol menü adları `App\Support\Vendors`'ta.
  - **Oyun açma:** `POST /game/launch-url`, `userCode = np_<id>`, dil hesabın dili, cevaptaki `message` = oyun adresi. `errorCode 2` (kullanıcı yok) → `/user/create` + bir kez tekrar. **Sadece TRY üye** (`casino.romaspin.currency`); TRY dışı üyede istisna (USD/EUR riski bu sağlayıcıda kapalı). `lobbyUrl`: canlı → Canlı Casino, mini → `/mini`, slot → `/slots`.
  - **Callback:** Rota `POST /api/casino/romaspin/{action}`, `action` = `(api/)?(balance|transaction|batch-transactions?)` → `RomaSpinCallbackController`. `throttle:1200,1` (tüm callback'ler RomaSpin'in tek IP'sinden gelir). Doğrulama `Authorization: Basic base64(clientId:clientSecret)`, `hash_equals`. Cevap `{success, message: bakiye, errorCode}`. Hata kodları: 2 kullanıcı yok, 4 yetersiz bakiye / hesap bloklu, 400 eksik alan, 401 yetkisiz.
  - **Transaction kuralı:** Idempotency `romaspin:<transactionCode>`. `isCanceled` → aynı `transactionCode`'lu bahsin iadesi (`<code>:cancel`); bahis bulunamazsa `casino.romaspin.cancel_unmatched` uyarısı. **Bahis = `amount < 0` veya kod `debit` ile bitiyor** (çelişirse bahis sayılır; bakiye eklemektense düşmek güvenli). Diğerleri kazanç, 0 tutar = `zeroWin`. Batch: `transactions` dizi ya da tek nesne.
  - **DOĞRULANMADI:** `amount` işareti ve iptal şekli. İlk gerçek oyunda `storage/logs/laravel.log` → `casino.romaspin.callback` (tam payload loglanıyor) kontrol edilecek.
- **`casino:sync {provider}`** komutu (goldpalace | romaspin). **Zamanlayıcı: `casino:sync romaspin` her gece 01:30 UTC (04:30 TR)**, `withoutOverlapping(60)`. Yeni oyunlar gelir, GoldPalace önceliği yeniden hesaplanır.
- **Canlı kontroller:** Şifresiz callback → `errorCode 401`, doğru Basic auth + olmayan üye → `errorCode 2` (ikisi HTTP 200).
- **RomaSpin'e iletilen/açık:** `mini-pragmatic /games/list` zaman aşımı (30 sn+). Evolution başta `/vendors/list`'te yoktu, RomaSpin açtı. PlayAce ilk denemede zaman aşımı, sonra düzeldi. Novomatic'in yeni **Lock 'N' Win** serisi (Charming Lady, Golden Book of Ra vb.) yok; soruldu.

### Oyun sayıları (30.09 akşam)
- **Slot: 3.911 aktif** = GoldPalace ~2.330 + RomaSpin 1.582 (1.052 yeni sağlayıcı + 530 ortak sağlayıcıda GoldPalace'ta olmayan; en çok Pragmatic 157, Hacksaw 107).
- **Canlı Casino: 223** (Evolution 75, Pragmatic Live 80, Ezugi 65, Dream Gaming / SA Gaming / PlayAce lobi).
- **Mini Oyunlar: 24** (Aviator 1, Spribe 9, InOut 11, SmartSoft 2, BGaming 1).
- Önce: slot ~2.370, canlı 405 (1GameX, depozitsiz, açılmıyordu), Sanal Bahis 91.

### Menü ve sağlayıcı değişiklikleri (Bölüm 0'daki menünün yerine)
- **Sanal Bahis kaldırıldı → Mini Oyunlar.** Rota `/mini` (`site.mini`, `SiteController::mini`, mod `mini` = `category=mini`). `/virtual` → `/mini` yönlendirmesi; `site.virtual` adı yönlendirmede duruyor (eski `route('site.virtual')` çağrıları kırılmaz). Slot modu `virtual` ve `mini`'yi dışlar. Menü: **Mini Oyunlar · Wegas Spor · Slot · Canlı Casino · Sonuçlar**; mobil alt menüde Mini Oyunlar şimşek ikonu. `site.mini` çevirisi: TR Mini Oyunlar, EN Mini Games, DE Minispiele, AR ألعاب سريعة.
- **1GameX tamamen pasif:** Senkronda `is_active=false`, veritabanındaki tüm oyunları pasif (405 canlı + 91 sanal). Sağlayıcı kaydı duruyor.
- **GoldPalace'ın `mini` kategorisi pasif** (37 oyun: AtlasV, Solidicon, BEON, Tydo, Spribe; hiç oynanmamıştı). GoldPalace senkron kuralı: `launch_enable && category != 'mini'`. Bu oyunlar ayrı API değil, slot listesinin (`/v4/game/games`) içinde `category=mini` olarak geliyor.

### GoldPalace alt agent (tek panel)
- GoldPalace ana hesabı **`thedoctor_try`** (Operator, NCS VIP'e bağlı, GGR %2,5, puan sistemi). Wegas şu an ayrı hesap `winoxbet_try` kullanıyor; tek panelde toplamak için ana hesabın altına **`wegas_try` (#400043576)** açıldı: Operator, TRY, GGR %2,5, State **Waiting** (GoldPalace onayı bekleniyor). **Puan aktarımı gerekmiyor**, ana hesaptan çekilir.
- Alt agent **zorunlu**: bir agent'ın tek callback adresi var ve iki sistem de `np_<id>` kullanıyor (aynı agent'ta kullanıcı kodları çakışır).
- Onaydan sonra: `wegas_try` ile gir → API sayfası → `.env` `GOLDPALACE_AGENT_CODE / GOLDPALACE_API_TOKEN / GOLDPALACE_CALLBACK_TOKEN` değişimi, callback `https://wegas11.com/api/casino/goldpalace/callback` (IP/HTTP'den alan adına geçiş). Canlıda üye yok, geçiş temiz.

### Panel
- **Üye oluşturma/düzenlemede komisyon alanı yok:** `StoreUserRequest` işlemi yapan bayiyse `commission_rate` → `exclude`; `UpdateUserRequest` düzenlenen üyeyse `exclude`; `HierarchyService::create` → `$data['commission_rate'] ?? 0`; `_fields.blade.php` `$showCommission`. Süperadminin bayi formunda komisyon duruyor.

### Testler ve iş akışı
- **201 test geçti**, `lang:check` **587 anahtar**. Yeni: `RomaSpinProviderTest` (12). Arapça ay adı testi `travelTo(2026-09-10)` ile takvimden bağımsız (ay sonunda Ekim'e taşıyordu). `SportTest` bir kez `UniqueConstraintViolation` ile düştü, üç tekrarda geçti (rastgele id çakışması; sağlamlaştırılacak).
- **Rota değiştiyse testten önce `route:clear` da şart:** rota önbelleği açıkken testler önbellekteki eski rota listesini kullanır (yeni rota 404).
- Sunucuda git kimliği: `Hybridus <hybridusevreni@gmail.com>`.
- Commit'ler: `2e37f5a` komisyon, `6f1a2ed` RomaSpin, `7327dc2` 1GameX canlı pasif, `7394841` Mini Oyunlar, `f000a43` GoldPalace mini pasif, `3ab8764` Arapça test, `95f867f` RomaSpin 20 slot sağlayıcısı, `b990493` harmanlama, `a4f6430` `casino:sync` + gece senkronu.

---

## 0b. 29.09.2026 güncellemesi — önce bunu oku (Bölüm 0'dan da yeni)

### Hiyerarşi ve para birimi modeli DEĞİŞTİ
- **Süperadmin çok para birimli** (owner gibi TRY/USD/EUR cüzdanı). `User::isMultiCurrency()` = owner **veya** süperadmin. `WalletProvisioner` bu hesaplara 3 cüzdan açar; eksi bakiye izni yine **sadece owner**'da.
- **Dil / para birimi / saat dilimi artık BAYİDE sabitlenir** (süperadmin bayi açarken seçer), üye bayiden miras alır. `HierarchyService::inheritedLocale()`: süperadmin ve bayi formdan, üye actor'dan. Süperadminin `currency` alanı sadece varsayılan gösterim.
- **Transfer kuralı** (`WalletService::transferCurrency($from, $to, ?Currency $requested)`): owner ↔ süperadmin istenen para birimiyle (verilmezse süperadminin varsayılanı, eski çağrılar bozulmaz); **iki süperadmin arası transfer yok** (currency_mismatch); taraflardan biri tek para birimliyse onun para birimi, farklısı istenirse hata. `transfer()` sona opsiyonel `?Currency $currency` aldı. `walletFor()` ve `SportLimits` (satır ~284) aynı kurala bağlandı.
- **Panel:** Owner'ın süperadmine bakiye formunda **TRY | USD | EUR seçici** (`users/index.blade.php`, Alpine `pickCurrency`, gizli `currency` alanı; "sizin bakiyeniz" hedefin para biriminden `ownBy` haritasıyla). Süperadmin → bayi formunda seçici yok, otomatik bayinin para birimi. Başlıkta çok para birimli hesapta 3 bakiye (zaten tüm cüzdanları listeliyordu).
- Bayi açma formunda dil/para/saat alanları süperadmine de açık (`_fields.blade.php`, `StoreUserRequest` kuralı owner+süperadmin). Saat dilimi **açılır liste** (UTC farkıyla, varsayılan Europe/Istanbul).
- Mevcut süperadmin **Volkan**'a USD/EUR cüzdanları açıldı (`WalletProvisioner::openFor`). `wallet:verify ok`.
- **Risk (açık):** Tüm para birimleri hemen açık kararı verildi. **GoldPalace ve 1GameX'in USD/EUR desteği sorulmadı**; USD/EUR üye oynarsa sağlayıcı tarafında tutarlar TRY sanılabilir. Wegas Spor zaten sadece TRY (`wegas_sport_available`).
- Kod: `ed686a9` (çekirdek), `934a59d` (panel formu + Volkan cüzdanları).

### Performans
- **Oyun görselleri proxy:** `App\Services\GameImages` sağlayıcı görselini indirir (≤8 MB, 15 sn), **360 px WebP** (kalite 72) yapar, `public/cache/g/{oyun_id}-{md5(image_url)[0..8]}.webp` olarak saklar (adres değişirse dosya adı değişir). Rota `/cache/g/{file}` → `GameImageController` (dosya yoksa üretir; üretemezse orijinal adrese yönlendirir). `_card.blade.php` bu adresi kullanır. Isıtma: `casino:warm-images` (arka planda çalıştırıldı, log `storage/logs/warm-images.log`). `/public/cache` .gitignore'da. Sonuç: görsel 110–180 KB → ~21 KB; slot sayfası ~12 MB → ~2 MB; ikinci istek nginx'ten 0,07 sn.
- **nginx:** 443 ve 80 bloklarına `location ^~ /cache/g/ { try_files $uri /index.php?$query_string; expires 30d; Cache-Control "public, immutable"; access_log off; }` (yedek `/root/bak/platform.nginx.pre_imgcache_*`). IP callback'ler etkilenmedi (401).
- **SportNames singleton** (`AppServiceProvider::register`): `sport_name()` her çağrıda yeni nesne oluşturduğu için çeviri tablosu her satırda yeniden okunuyordu. Sonuçlar 668 → 9 sorgu, 1,1 sn → 0,3 sn; ana sayfa 0,26–0,36 sn.
- Kod: `0ef8383` (görsel proxy), `9faf3ed` (singleton).

### Panel iyileştirmeleri (29.09)
- Kullanıcı oluşturma başlığında oluşturulacak rol ("Kullanıcı oluştur · Süperadmin/Bayi/Üye"); kullanıcı adı/şifre alanlarında tarayıcı otomatik doldurma kapalı (`autocomplete="off"` / `new-password`). Rol oluşturana göre otomatik: owner→süperadmin, süperadmin→bayi, bayi→üye.
- **Alt kullanıcı limiti** formdan ve listeden kaldırıldı (kolon duruyor, boş = sınırsız).
- Hesap hareketleri menü/başlık etiketi role göre: owner "Süperadmin hareketleri", süperadmin "Bayi hareketleri", bayi "Üye hareketleri" (`PanelMenu::ledgerLabel()`, `wallet.menu_{rol}`).
- **Düzeltme borcu** sayfasında açıklama kutusu (4 dil). Kendi spor sistemine ait sayfalarda (Tüm Kuponlar, Kupon Sorgulama, kupon detayı, Riskli Kuponlar, Spor ayar sayfaları) `OWN_SPORT_ENABLED` kapalıyken sarı **"Bu bölüm şu an kullanılmıyor"** kutusu (`panel/partials/own_sport_notice.blade.php`). **Metinlerde Tipo adı geçmez** — kullanıcıya dönük her yerde sadece "Wegas Spor".
- **Casino Hareketleri / Casino Girişleri:** üye adı filtresi (`<x-panel.filter-bar :username="true">`, kısmi eşleşme, hiyerarşi kapsamının üzerine). Casino Hareketleri toplamları artık **filtredeki tüm turlar** üzerinden (eskiden sadece ekrandaki son 100 tur).
- Kod: `3eb89c7`, `d928381`, ve devamı.

### Çalışma notları (production)
- Önbellekler açık. Kod değişikliği sonrası sıra: `config:clear` → `php artisan test` → `config:cache` → `route:cache` (rota değiştiyse) → `view:clear` + `view:cache` → `systemctl reload $(systemctl list-units --type=service --no-legend | grep -o 'php[0-9.]*-fpm' | head -1)`. Test sonucu ne olursa olsun önbellekleri geri kurmak için `;` ile bağlanır.
- Rota eklerken `Route::get('/', ` gibi kalıplara güvenme (birden çok eşleşme); en güvenlisi dosya sonuna üst seviyede eklemek.
- Python ile metin değiştirirken girinti farklı satırlarda aynı alt dizinin geçebileceğini unutma (12 boşluk, 16 boşluklu satırın içinde de eşleşir) → aramayı `\n` ile satır başından yap.
- Var olmayan bir sınıfı `catch` etmek PHP'de hata vermez, sessizce yakalamaz (WalletException `App\Services` altında).
- Testler: **189 geçti**. `lang:check` **586 anahtar**. Saate bağlı "empty today bulletin" testi bir kez tek başına geçip süitte düştü, tekrarlarsa sağlamlaştırılacak.

---

## 0. 28.09.2026 güncellemesi (canlıya çıkış) — önce bunu oku

### Canlı durum
- **28.09.2026 ~21:15 canlıya çıkıldı.** `platform:go-live --force` çalıştı: sadece **owner (#1)** kaldı (yeni güçlü şifre Yusuf'ta), `users` sayacı **1000** (sağlayıcılar `np_<id>` / `wegas:<id>` ile tanıdığı için eski test ID'leri tekrar kullanılmaz), defter boş, `wallet:verify ok`. Oyun kataloğu (2.868) ve Fenix maçları korundu.
- **Production:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://wegas11.com`; `config:cache`, `route:cache`, `view:cache` **açık**. Kod/ayar değişikliğinden sonra: `sudo -u www-data php artisan optimize:clear` → değişiklik → tekrar üç cache. **Sunucuda test çalıştırmadan önce `config:clear` şart** (TestCase koruması zaten engeller).
- **Yedekler:** `/root/bak/ncsvip_pre_golive_20260928_2110.sql.gz` (+ Yusuf'un bilgisayarında kopya), `/root/bak/env_pre_golive_*`, `/root/bak/env_pre_prod`. Elle düzenlenen dosyaların öncesi `/root/bak/*.pre_*`.
- Demo hesaplar ve demo maçlar **silindi**; `demo:reset` artık kullanılmaz.

### Ürün kararları (öncekilerin yerine geçer)
- **Şimdilik sadece Türkiye (TR/TRY).** Kendi spor API'si hazır olunca tüm dillerde uluslararasına açılınacak. `wegas_sport_available()` (TRY + Arapça değil) aynen kalıyor.
- **Spor = Wegas Spor (Tipo iframe, NCS köprüsü).** Kendi bülten/kupon sistemi **kapalı**: `OWN_SPORT_ENABLED=false` (`config/sport.php` → `own_book_enabled`), `EnsureOwnSportEnabled` middleware'i 14 route'u (bülten, maç detayı, canlı, oran ekleme, kupon işlemleri, kombine, Kuponlarım) **404** yapar; öncelik listesinde auth'tan önce. Açık kalanlar: `/sport/results`, `/wegas-spor`. Kod silinmedi, tek ayarla geri açılır.
- **Menü:** Sanal Bahis · Wegas Spor · Slot · Canlı Casino · Sonuçlar. Canlı Bahis ve Kuponlarım menüden kalktı. **Mobil alt menü:** Wegas Spor · Sanal Bahis · Slot · Canlı Casino · Hesabım.
- **Ana sayfa:** Bölümler duruyor (günün maçı, yaklaşan, popüler oranlı, günün kombinesi) ama tüm maç/oran/kombine bağlantıları **Wegas Spor'a** gider (`_home_odds` oran butonları link). "Canlı bahis" ve "Spor bülteni" kutuları kaldırıldı. Hero metni: "Maçları Wegas Spor'da canlı takip et, bahsini yap." (4 dil).
- **Sanal Bahis:** `/virtual` (`site.virtual`), GoldenRace'in 91 oyunu (1GameX `category=virtual`). `SiteController::games(string $mode)` = `slot | live | virtual`; sanal oyunlar slot listesine karışmaz (slot = `is_live=false` ve kategori `virtual` değil, NULL dahil).
- **Sonuçlar:** Sade tam sayfa (lig menüsü, kupon, sorgulama yok; satırlar link değil), veri Fenix'ten (aşağıda).

### Sağlayıcılar (güncel)
- **1GameX:** Gerçek protokol commit'lendi (`f13e216`). Senkron `OneGameXProvider::syncGames()` (ayrı artisan komutu yok): 506 oyun → `live` 405 **aktif** (Pragmatic Play Live 245, Evolution 127, Vivo Gaming 33), `virtual` 91 **aktif** (GoldenRace), `crashgames` 4 (Aviator, JetX, Rocketman, Spaceman), `slots` 4, `minigames` 2 → **pasif**. Kural kodda: `is_live = category=='live'`, `is_active = live || virtual`. Geçersiz imzada HTTP 200 + `result:0` (401 değil), sadece `ONEGAMEX_VERIFY_SIGNATURE=true` iken. **Depozit yapılmadı → masa açılmıyor**; depozitten sonra bir bahis, log'da `signature_valid`, doğruysa bayrak true.
- `Vendors::NAMES`'e 1GameX markaları eklendi (Evolution, Pragmatic Play Live, Vivo Gaming, GoldenRace, BGaming, crash oyunları).
- **GoldPalace vendor:** 2.362 oyunun `vendor`'u boştu (fillable düzeltmesinden önce senkronlanmış); `Vendors::fromImage(image_url)` ile yerelde dolduruldu (18 sağlayıcı).
- **Fenix sonuçları:** `https://fenix5.com/resultbot.php?eventid=<id>` (GET) maç bazında market sonuçları döner. Final skor = **"Correct Score" (id 537)** kazanan seçenek (`3:1`); ilk yarı = "1st Half - Correct Score" varsa o, yoksa ev/deplasman 1. yarı toplam gol marketleri (**2529 / 2531**, en yüksek kazanan "Over n.5" → n+1). Oran durumu: **3 = kazandı, 4 = kaybetti, 5 = iade**. `is_full_results_update` false ise yazılmaz; ilk yarı finalden büyükse ilk yarı yazılmaz. Servis `App\Services\Sport\FenixResults`, komut `sport:fenix-results` (10 dk, `withoutOverlapping(15)`, tur başına 40 maç, maç başına 30 dk'da bir deneme, `score_source=fenix`, `manual` asla ezilmez). Adres `config/fenix.php` → `resultbot_url`. Maç başı ~1,5 sn.
- `resultApi` **POST** + `market_uid` ister, market bazlı `won/lost/not_found` döner (skor yok) → kupon sonuçlandırma içindir. `liveEvents` sadece `STARTED` maçları verir, biten maç listeden düşer (final skoru buradan alınamaz).

### Alan adı ayrımı
- **`panel.wegas11.com`** (Cloudflare A kaydı, gri bulut). Sertifika `certbot certonly --nginx --cert-name wegas11.com -d wegas11.com -d www.wegas11.com -d panel.wegas11.com --expand` ile genişletildi (**certonly nginx dosyasına dokunmaz**). nginx: iki `server_name` satırına panel eklendi, 80 bloğuna panel için HTTPS yönlendirmesi elle yazıldı; IP üzerinden callback'ler (HTTP) çalışmaya devam ediyor (kontrol: POST IP callback → 401).
- `.env`: `SITE_DOMAIN=wegas11.com`, `PANEL_DOMAIN=panel.wegas11.com` (`config/domains.php`; boşsa ayrım kapalı). `App\Support\Domains` + `SeparateDomains` middleware (auth'tan önce): panel alan adında sadece `/panel/*`, `/login`, `/logout`; diğerleri `/panel`'e. Site alan adında `/panel/*` → panel alan adına.
- **Giriş kapısı** (`LoginController`, şifre doğrulandıktan sonra): panelde sadece owner/süperadmin/bayi, sitede sadece üye. Yanlış kapı = şifre hatasıyla **aynı** `auth.failed` mesajı, rate limit sayılır, log `auth.login_failed` + `wrong_gate`.
- Panel girişi ayrı sade sayfa `auth/panel-login.blade.php` (site layout'u yok, logo, "Yönetim Paneli", yönetici notu, bayraklı dil seçici, `noindex`). Site girişindeki "şifrenizi bayinizden isteyin" notu panelde yok (`_form` `hint` parametresi).
- Panel üst çubuğunda **"Siteyi göster"** (yeni sekme, `Domains::siteUrl()`); oturumlar alan adına özel olduğu için yönetici siteyi **ziyaretçi** olarak görür, oyun açamaz.
- Dil: giriş yapmış kullanıcıda her istekte hesabın `language`'ı geçerli (`?lang` yok sayılır); ziyaretçide `?lang` oturumda tutulur. Hesap dili süperadminden miras.

### Tasarım
- **Logo:** WEGAS11 (mavi/gümüş V), şeffaf PNG'ler `public/images/brand/` (`wegas-header.png` 458×128, `wegas-mark.png`, `favicon-32.png`, `apple-touch-icon.png`, `icon-512.png`); header'da yazı yerine logo, `theme-color #0B0D22`.
- **Bayraklar:** `public/images/flags/{tr,gb,de,sa}.svg` (flag-icons, MIT). Windows'ta emoji bayrak görünmediği için SVG. Header dil menüsü, site girişi ve panel girişinde.
- **Mobil:** Ana sayfa ızgaralarında `grid-cols-1` (uzun takım adında taşma), `body` `overflow-x-clip`.

### Testler
- **186 geçti.** Yeni: `FenixResultsParseTest` (3), `OwnSportClosedTest`, `DomainSeparationTest` (3), `GoLiveCommandTest` (3). `phpunit.xml`'de `OWN_SPORT_ENABLED=true`, `SITE_DOMAIN=""`, `PANEL_DOMAIN=""`. `lang:check` 576 anahtar.
- `platform:go-live`: varsayılan **rapor kipi**; `--force` + `CANLIYA-AL` + yeni owner şifresi (≥12). Tüm FK'ler RESTRICT → FK kontrolü kapatılıp silinir; MariaDB `TRUNCATE` trigger tetiklemez, SQLite'ta trigger SQL'i saklanıp geri kurulur. Prova `ncsvip_prova` kopyasında yapıldı (`sudo -u www-data env DB_DATABASE=ncsvip_prova php artisan ...`), sonra silindi.
- Son commit'ler: `39c0825` (go-live komutu), öncesi `b432682` panel bayrakları, `d350b8c` Siteyi göster, `a68a102` alan adı ayrımı, `5294752` spor kapatma, `ed78656` Fenix sonuçları.

---

## 1. Genel

- **Ürün:** Kapalı devre bahis platformu (spor + slot + canlı casino), bayi tabanlı. (Açık kayıtlı online sistem WinoxBet ayrı üründür, bu dosyanın kapsamı dışında.)
- **Diller:** TR, EN, DE, AR (Arapça RTL). **Arapça ana hedef pazar**, özellikle sporda.
- **Marka adı:** **Wegas** (`APP_NAME=Wegas`, `brand()->name()`). Logo şimdilik yazı: "Wegas." (nokta vurgu renginde).
- **Domain:** **wegas11.com** — Cloudflare hesabında (nameserver: addyson / osmar.ns.cloudflare.com). `A @` → 91.208.197.142, `CNAME www` → wegas11.com, ikisi de **DNS only (gri)**. **SSL var** (Let's Encrypt, certbot, nginx `sites-enabled/platform`); alan adından HTTP → HTTPS 301. Turuncu bulut henüz yok. Arama motoru/AI botları Cloudflare'de engelli.
- **IP üzerinden HTTP bilinçli olarak açık:** Sağlayıcı callback'leri (GoldPalace, 1GameX) `http://91.208.197.142/api/casino/<sağlayıcı>/callback` adresine geliyor. certbot 80 portunu `return 404` yapmıştı; 80 bloğu alan adı dışındaki isteklerde uygulamayı sunacak şekilde düzeltildi (yedekler `/root/bak/platform.nginx.*`). Callback adresleri ileride `https://wegas11.com/...`'e taşınacak (sağlayıcı panellerinden).
- **Referans model:** Hiyerarşi ve yetki kararlarında **NCS VIP** esas alınır (Tipo paneli değil).

## 2. Altyapı

| Konu | Değer |
|---|---|
| Sunucu | 91.208.197.142 (AlexHost VPS, 4 çekirdek / 8 GB / 80 GB), hostname `kapalidevre`, SSH alias `winoxbet` |
| OS | Debian 13 |
| Stack | Nginx, PHP 8.4-FPM, MariaDB (tüm tablolar InnoDB), Redis (oturumlar dahil, `SESSION_DRIVER=redis`), Node/Vite |
| Uygulama | Laravel 13, Blade + Tailwind + Alpine, Chart.js (npm/Vite, CDN yok) |
| Proje dizini | `/var/www/platform` (nginx site adı `platform`) |
| Repo | `git@github.com:Hyb2779/ncs-platform.git` (private, deploy key "kapalidevre-sunucu", read/write) |
| Veritabanı | `ncsvip`, kullanıcı `ncsvip`@localhost (bilgiler `/root/.ncsvip_db_credentials` ve `.env`) |
| Referans kod | `/root/reference/ncs-vip/` (NCS VIP GoldPalace entegrasyonu; spor kodu henüz kopyalanmadı), `/root/reference/design/` (onaylı taslak `Main.dc.html`, `Mobil.dc.html`) |
| Yedekler | Elle düzenlemelerde önceki dosyalar `/root/bak/` altında |

### Çalışma kuralları
- Cursor ile çalışılıyor; kurallar `.cursor/rules/proje.mdc` içinde (alwaysApply). Cursor kredisi yokken işler sohbetten terminal komutlarıyla, tek tek yürütülüyor.
- Artisan komutları **her zaman** `sudo -u www-data php artisan ...` ile. Scheduler cron'u `www-data` kullanıcısında.
- Her anlamlı adımda ayrı commit + **push**. Commit'e sadece ilgili dosyalar eklenir (`git add -A` değil).
- Büyük işlerde önce plan/şema gösterilir, onaydan sonra kod yazılır. Her faz sonunda 390px + masaüstü ekran görüntüsü.
- Aynı anda iki agent aynı dizinde çalıştırılmaz; işler tek agent'a sırayla verilir.
- Gizli bilgiler sadece `.env`'de; koda, loga, commit'e girmez.
- SSH şifreli giriş kapalı, sadece anahtar.
- **Test güvenliği:** Testler SQLite `:memory:` ile çalışır. `config:cache` açıkken test çalıştırılmaz (gerçek DB'yi siler). `tests/TestCase.php` config önbelleği açıksa, ortam `testing` değilse veya varsayılan DB `:memory:` değilse testi başlatmaz. Geliştirme sırasında config önbelleği kapalı tutulur.
- Frontend değişikliğinden sonra `npm run build` (root ile; `public/build` root'a ait ve repoda değil), Blade değişikliğinden sonra `view:clear`.

## 3. Mimari kurallar (değişmez)

### i18n
- Arayüz çevirileri sadece `lang/{tr,en,de,ar}` dosyalarında; veritabanı overlay'i yok.
- Hardcoded metin yasak; her metin `__()` ile.
- `php artisan lang:check` dört dili karşılaştırır; git pre-commit hook eksik anahtarda commit'i engeller.
- Tailwind'de sadece mantıksal yön sınıfları (`ms/me/ps/pe/start/end`), RTL otomatik. Ortalama gerekiyorsa `left/translate` değil flex kullanılır.
- Fontlar: gövde Manrope, rakamlar Barlow Condensed, Arapçada Cairo.
- Arapçada oranlar ve tutarlar batı rakamlarıyla (1.85), tarih/ay adları Arapça. Grafiklerde de batı rakamı, Arapçada eksen aynalı.

### Hiyerarşi
- Tek `users` tablosu: `role` (owner, superadmin, bayi, uye), `parent_id`, `path` (materialized path), `depth`, `superadmin_id`.
- Owner sadece süperadmin, süperadmin sadece bayi, bayi sadece üye oluşturur.
- **Dil, para birimi, saat dilimi süperadminde sabitlenir**, altındakiler miras alır, değiştiremez. Kur dönüşümü yok.
- Herkes **kendisini ve alt ağacını** görür; **üst zinciri asla** (ad ve bakiye gizli, sadece "Üst hesap" etiketi), **yatay dalları asla**. Başka dala ID ile erişim 404.
- `user_limit`, `commission_rate`, `status` (active/passive/banned; üst zincirden biri pasifse giriş yok).
- `activity_logs` tüm yönetim işlemlerini kaydeder.

### Rol yetkileri (NCS modeli)
- **Owner:** Her şey. Bakiye işlemini sadece doğrudan alt hesaplarıyla (süperadmin) yapar.
- **Süperadmin:** Kendi ağacı. Spor limitleri (owner tavanının altında), kupon iptali, düzeltme borcu ve riskli kuponlar ekranları. Bayilerine ve **ağacındaki tüm üyelere** bakiye yükler/çeker (para süperadminin kendi cüzdanından; bayinin bakiyesine dokunulmaz, bayi bu hareketi "Üst hesap" olarak görür).
- **Bayi:** Sadece üye ekler, üyeyi pasif/aktif eder, üyeye kredi yükler/üyeden çeker, üyenin şifresini sıfırlar. Üye kuponlarını (canlı + arşiv), Kupon Sorgulama'yı, hesap hareketlerini, casino oyun geçmişi/oturumlarını **görür**; hiçbir şeye müdahale edemez. Kupon iptali talebini süperadmine sistem dışında iletir. Spor Ayarları, Düzeltme borcu, Riskli kuponlar bayide yok (menü + sunucu 404, iptal 403).
- **Şifre sıfırlama ve hızlı durum:** Herkes kendi ağacındaki hesaplar için (kendisi ve üst zinciri hariç). `POST /panel/users/{user}/password` (boşsa 10 karakterlik şifre üretilir, sonraki sayfada bir kez gösterilir), `POST /panel/users/{user}/status` (aktif ↔ pasif; banlı hesap sadece Düzenle'den). İkisi de `HierarchyService::update()` üzerinden (yetki + activity log).
- Bayiye yeni yetki/ekran eklenmeden önce Yusuf'a sorulur.

### Oturum güvenliği
- `EnsureAccountActive` middleware'i (web grubu) her istekte `HierarchyService::loginBlocked()` kontrolü yapar: kullanıcı veya üst zinciri pasif/banlıysa oturum düşer (JSON isteklerde 401). Girişteki kuralla birebir aynı fonksiyon.
- Şifre değişince açık oturumlar düşer: oturumda kullanıcı id'sine bağlı şifre özeti (`account_pw_{id}`) tutulur. Laravel'in `AuthenticateSession`'ı kullanılmaz (tek özet tuttuğu için aynı oturumda kullanıcı değişince yanlış alarm veriyordu).

### Cüzdan
- `wallets` (user_id + currency unique, `allow_negative` sadece owner cüzdanlarında, `last_sequence`) ve değişmez `wallet_transactions` (UUID id, **`sequence`** — cüzdan başına 1'den başlayan sıra, unique `(wallet_id, sequence)`; balance_before/amount/balance_after, type, product: sport/slot/live_casino/transfer/bonus/adjustment, `idempotency_key` UNIQUE).
- `wallet_transactions` UPDATE/DELETE veritabanı trigger'ı ile yasak (`wallet_transactions_no_update`, `_no_delete`); düzeltme ters kayıtla.
- Bakiye **sadece WalletService** üzerinden değişir. Sıra: transaction → **önce cüzdan satırı `FOR UPDATE`** → idempotency kontrolü kilitsiz okuma → yaz. (Önceden idempotency anahtarı `FOR UPDATE` ile okunuyordu; olmayan anahtarda gap lock alıp paralel INSERT'lerde deadlock 1213 üretiyordu — 27.09'da düzeltildi.) İki cüzdanda id sırasına göre kilit.
- `last_sequence + 1` bakiye güncellemesiyle aynı sorguda yazılır; kilit altında olduğu için çakışmaz.
- Deadlock (1213) ve lock wait timeout (1205) WalletService içinde otomatik yeniden denenir (en fazla 3, 10–50 ms); idempotency sayesinde çift kayıt oluşmaz.
- **Owner kredi modeli:** Mint yok. Owner süperadmine doğrudan yükler, owner cüzdanı eksiye düşer; ekranda "Dağıtılan kredi" olarak pozitif. Owner'ın TRY/USD/EUR için ayrı cüzdanı var.
- Bakiye ekle/çıkar: doğrudan alt kullanıcıya + süperadminden ağacındaki üyelere; çocuk bakiyesinden fazla çekilemez, işlemi yapanın bakiyesinden fazla yüklenemez (owner hariç).
- **Düzeltme borcu (settlement overdraft):** `wallets.settlement_overdraft_amount`. CHECK: `balance >= -settlement_overdraft_amount OR allow_negative = 1`. Sadece CouponSettler'ın ters kayıt adımı yazar; üye yalnızca düzeltme tutarı kadar eksiye iner. Bakiye >= 0 olunca 0'a çekilir. Eksideyken üye kupon/slot/casino oynayamaz, eksideki üyeden bakiye çekilemez (yükleme borcu kapatır). Uyarı listesi owner ve süperadmine görünür (kendi ağacıyla sınırlı).
- `php artisan wallet:verify` her gece 03:30 UTC. Hareketleri **`sequence`** ile sıralar, numaraların boşluksuz olduğunu ve önceki + tutar = sonraki zincirini kontrol eder. (Önceden `created_at, id` ile sıralıyordu; UUID + saniye hassasiyeti yüzünden aynı saniyedeki hareketlerde sahte alarm üretiyordu.)
- Hesap hareketleri: her transfer tek satır, "konu hesap" kuralı, önceki + tutar = sonraki.

## 4. Sağlayıcılar

| Sağlayıcı | Kullanım | Durum |
|---|---|---|
| **GoldSlotPalace** (agent `winoxbet_try`) | Slot | Çalışıyor. Callback: `http://91.208.197.142/api/casino/goldpalace/callback`. Dil kodları: TR 7, EN 1, DE 10, AR 15. 2.362 oyun senkron. |
| **1GameX** | Canlı casino | **Kod yazılıyor (yarım, commit edilmedi).** Wegas'a ayrı token: `winoxbetlivecasinotry` (#2245, TRY), panelde Wallet Endpoint `http://91.208.197.142/api/casino/onegamex/callback`. `.env`: ONEGAMEX_TOKEN_NAME (API `token` alanı), ONEGAMEX_PASSWORD (panel Password, `password` alanı), ONEGAMEX_SECRET_KEY (imza anahtarı), ONEGAMEX_API_URL=https://api.1gamex.net, ONEGAMEX_VERIFY_SIGNATURE=false. GameList doğrulandı (9 marka). |
| **API-Football** (Pro, günde 7.500 istek) | Spor verisi | Çalışıyor. Bookmaker id 8 (Bet365). |
| **Anthropic API** | Takım/lig adı çevirisi | `ANTHROPIC_API_KEY` henüz eklenmedi. |

- Sağlayıcıya giden kullanıcı kodu: `np_` + user id.
- Casino altyapısı sağlayıcıdan bağımsız: `CasinoProvider` interface, tek callback rotası `/api/casino/{provider}/callback`, idempotency `provider:transaction_id`. Demo sağlayıcı sadece production dışında aktif.
- **0 tutarlı win:** GoldPalace (ve genel olarak sağlayıcılar) kayıp turda 0 ₺ "win" gönderir. `CasinoWallet::win()` 0 tutarda cüzdana hareket yazmadan `game_rounds`'a tur kaydı açar ve bakiyeyi döner (önceden `wallet.invalid_amount` → 500 → oyun krediyi 0 gösteriyordu).
- **1GameX protokolü (NCS VIP'teki çalışan koddan):** İstek `POST /GameList`, `/OpenGame` gövdesi `{token, password, [userId, gameId], signature = sha1(json_encode(gövde, UNESCAPED_SLASHES) + secret_key)}`; OpenGame'de `language`, `exitURL` query. GameList: `games` = {marka: [{id, name, image, type, uuid, thumbnails{landscape,portrait}, category}]}. Callback tek adres: gövdede `bet`/`win` → play (bahis+kazanç tek istek), sadece `amount` → refund, diğer → getBalance; alanlar userId, transactionId, roundId, gameId, gameUUID, brand. Cevap `{result 1/0, message, balance, signature = sha1(gelen_imza + secret_key)}`. Gelen imzanın hesaplanışı belgelenmemiş; tahmin sha1(json(gövde−signature)+secret). **NCS VIP gelen callback imzasını doğrulamıyor (güvenlik açığı).**
- **Wegas Spor (Tipo iframe, kullanıcıya "Wegas Spor" adıyla):** NCS VIP'in Tipo hesabı üzerinden, **NCS köprüsüyle**. Tipo'da oyuncu `wegas:<üye id>`. Tipo callback'leri NCS VIP'e (`/callback/sportsNew`) gelir; `wegas:` önekliler imzalanıp Wegas'a (`POST /api/bridge/tipo`) iletilir, cevap Tipo'ya aynen döner. İframe oturumu: Wegas → NCS VIP `POST /callback/wegas-session` → `createSession('wegas:<id>')`. İmza: `X-Bridge-Timestamp` + `X-Bridge-Signature = HMAC-SHA256(zaman.gövde, NCS_BRIDGE_SECRET)`, 60 sn, IP kısıtı (Wegas: `NCS_BRIDGE_ALLOWED_IPS=91.229.239.212`, NCS VIP: `WEGAS_BRIDGE_ALLOWED_IPS=91.208.197.142`). İşlemler Wegas'ta `sport` ürünü, idempotency `tipo:<tx_id>` / `tipo:rollback:<tx_id>`, referans `tipo:<bet_id>`; en fazla 10.000 ₺ bahis. Sekme: sadece üye, TRY, Arapça değil (`wegas_sport_available()`), `/wegas-spor`. NCS VIP commit `934ca72`.
- **Fenix (fenix5.com) — tek spor kaynağı:** `prematchEvents` (~19,5 MB, ~1.150 etkinlik), `liveEvents` (~1,2 MB, ~180 canlı), `resultApi` (market_uid ile sonuç). API anahtarı yok; Wegas sunucusu doğrudan erişebiliyor. Yanıtta `types` sözlüğü (1.838 tip: market_name, selection_name, handicap, sport_id). Etkinlikte eventid, competition_*, country_name (Türkçe), home/away id+ad, match_time, mbs, betradar_id, live_metadata (Betradar tipi canlı veri), odds {tip: {odds, market_uid}}.
- **API-Football kaldırılıyor:** Zamanlamadan çıkarıldı (sync-leagues/fixtures/odds/results, live-sync, settle-check). Kod (`ApiFootballClient`, `SportSync`, `LiveSync`, `SettleCheck`, `FootballBudget`) sonra silinecek; abonelik uzatılmayacak.
- **Güvenlik notu:** Sağlayıcı token'ları, 1GameX secret, API-Football anahtarı ve owner şifresi sohbetlerde açık paylaşıldı; canlıya çıkarken hepsi yenilenecek (geliştirme aşamasında sorun değil).

## 5. Spor (API-Football)

- Tablolar: sport_countries, sport_leagues, sport_teams, sport_fixtures (`elapsed` dahil), sport_markets, sport_odds, sport_translations, sport_sync_states, coupons, coupon_selections, sport_limits, daily_stats.
- Marketler: Maç Sonucu (bet 1), Çifte Şans (12), 1.5/2.5/3.5 Alt-Üst (5), Karşılıklı Gol (8), İlk Yarı Sonucu (13). Seçenekler outcome kodu ile, kanonik sıra.
- Marj motoru: global → lig → maç → market (superadmin override alanı var), 2 ondalık, min oran 1.01.
- İstek bütçesi: tüm çağrılar `ApiFootballClient` üzerinden, Redis sayaç; 6.500'ü geçince sadece kritik işler (`critical: true`). Günlük istek raporunda senkron, settle-check, live-sync ayrı satır.
- Senkron: ligler günde 1, fikstür 3 saatte bir, oranlar 3 saatte bir (2 saatten az kalanlar 30 dk).
- Takım/lig/ülke adları `sport_name()` ile; çeviri yoksa İngilizce. `source=manual` kayıtlar ezilmez.
- **CouponCalculator:** toplam oran = oranların çarpımı, 2 ondalığa yuvarlanır; olası kazanç = tutar × yuvarlanmış toplam oran. Arayüz ve sunucu aynı sınıfı kullanır.
- Kupon onayı: oranlar sunucudaki güncel değerden, tek transaction, idempotency, tekli modda her seçim ayrı kupon, CouponLimitGuard, "oran değişirse kabul et". **İptal sadece owner ve süperadmin** (gerekçeyle, iade), bayi 403; oyuncu iptali varsayılan kapalı (`cancel_minutes = 0`). Kupon detayında iptal formu bayiye gösterilmez.
- Menü: Spor `/sport`, Canlı Bahis `/sport/live`, Slot `/slots`, Canlı Casino `/live-casino`, Sonuçlar `/sport/results`.

### 5.1 Oynama anı ve canlı durum
- Kupon onayında, aynı işlemde: `coupons.played_at`; seçimde `kickoff_at`, `placed_status`, `placed_minute`, `placed_home`, `placed_away`. Gösterim: `Oynandı: 26.09 21:14 · Maç önü` / `Oynandı: 26.09 21:14 · 34' 1-0`.
- `sport:live-sync` 2 dk'da bir tek `live=all` isteği. Çalışma koşulu: (a) kickoff'u geçmiş ve bekleyen kupon seçimi olan fikstür **veya** (b) son 4 saatte başlamış ve kapanmamış bülten fikstürü. Soft cap'te durur. Canlıdan düşen fikstürler tek `ids=` isteğiyle çekilir; FT/AET/PEN ise skor yazılır ve `SettleFinishedFixtures` job'u hemen sonuçlandırır. Arada "Maç bitti, sonuç bekleniyor". Koşul sağlanmazsa istek atılmaz ve `sport_sync_states` güncellenmez (uzun süre eski tarih görünmesi normal).
- Kuponlarım seçim satırı: canlı `67' · 2-1` (nabız), devre arası `İY · 1-0`, bitti `MS 2-1`, başlamadı başlama saati. 60 sn'de bir hafif JSON ile yenilenir; başka üyenin kuponu 404.
- **Canlı sayfa ve skor şeridi (27.09):** Durum metni `sport_clock($fixture)` ile (canlıysa `67'`, devre arası/diğer durumlar `sport_status()`). Satırlarda `data-live-fixture` / `data-live-clock` / `data-live-score`. `GET /sport/live/data?ids=…` (en fazla 100 id, herkese açık maç verisi) → `{clock, score, live}`; `app.js` sayfa açıkken 60 sn'de bir çağırır, sekme arka plandayken istek atmaz, biten maçı soluklaştırır. Yeni başlayan maç listeye sayfa yenilenince gelir.

### 5.2 Otomatik sonuçlandırma (Faz 6c)
- `sport:settle-check` 10 dk'da bir (withoutOverlapping). Aday: `kickoff_at + 110 dk` geçmiş, pending seçimi olan ve kuponu pending olan fikstür. `score_source=manual` API'ye gitmez. `ids=` 20'li gruplar, `critical: true`.
- Config `config/sport.php`: `settle_after_minutes=110`, `void_after_hours=48`, `void_warn_hours=6`, `stale_after_hours=6`.
- Durumlar: FT/AET/PEN → **90 dk skoru** ile tüm marketler, İY `score.halftime` ile; uzatma/penaltı sayılmaz. Karar `fixture.timestamp` (gerçek başlama) değerine bağlı: `kickoff_at + 48 saat` içindeyse sonuçlandır, dışındaysa void. PST/CANC/ABD/AWD/WO/SUSP/INT → 48 saat dolunca void. `kickoff_at + 6 saat` geçmiş ve hâlâ NS/canlıysa owner uyarı listesine "elle sonuçlandır".
- `SelectionEvaluator`: market + outcome + skor → won/lost/void.
- `CouponSettler`: kupon satırı FOR UPDATE. Herhangi seçim lost → kupon lost. Hepsi sonuçlandıysa void seçim oran 1.00; hepsi void → kupon `void`, iade.
- Ödeme `coupon:{id}:settle:{revision}`, ters kayıt `coupon:{id}:reverse:{revision}`.
- **Elle skor / düzeltme:** owner maç detayında; İY skoru, 90 dk skoru ve oynanma tarihi zorunlu; `score_source=manual`. Düzeltmede önce ters kayıt, sonra revision+1; gerekirse düzeltme borcu.
- İptal edilmiş kupon detayında iptal eden, bakan kişiye göre "Üst hesap" olarak gizlenir (bayi, süperadminin iptalini "Üst hesap" görür).

### 5.3 Spor limitleri
- **Sadece owner ve süperadmin düzenler** (bayi: menü yok, görüntüle/kaydet/geri yükle 404; `SportLimits::guardActor` da bayiyi reddeder). Etkin değer zinciri owner → süperadmin; süperadmin kendi satırını kaydeder, tüm ağacına uygulanır.
- Owner varsayılanı **para birimi başına** ayrı satır. Etkin değer = zincirdeki en kısıtlayıcı (tavanlarda en küçük, tabanlarda en büyük). `null` = Sınırsız, sadece üst de sınırsızsa seçilebilir. Alt seviye üst tavanı aşamaz, tabanın altına inemez (form + sunucu). Taban/tavan tanımı tek yerde: `SportLimitFields`. Her kayıt activity_logs'a.
- İpucu ("Üst sınır: X") ve hata mesajı ("En fazla X olabilir") aynı biçimleyiciyle: `SportLimitFields::display($field, $value, $currency)` → `100 ₺`, `10.000,50 €`, oran `30.00`.
- Taban alanları: `min_stake`, `min_coupon_odds`, `min_odds_prematch`, `min_odds_live`. Diğerleri tavan.
- Alanlar: `cash_out_enabled`, `cancel_minutes`, `live_close_minute`, `max_selections`, `min_coupon_odds`, `max_coupon_odds`, `min_stake`, `max_stake_general`, `max_stake_single`, `max_stake_live`, `max_stake_per_fixture`, `max_stake_per_outcome`, `repeat_limit_single`, `repeat_limit_combo`, `daily_max`, `max_payout_general`, `max_payout_single`, `max_payout_live`, `max_payout_live_single`, `min/max_odds_prematch`, `min/max_odds_live`.
- Kombine en az 2 seçim sabit kural. Üye toplamları cüzdan kilidinden sonra aynı transaction'da. Oran tavanını aşan oran bültende kilitli, onayda reddedilir.
- Varsayılanlar (TRY / USD / EUR): min tutar 1/1/1; max tutar genel/tekli 10.000/250/200; canlı 10.000/100/100; maç başı 10.000/500/400; seçenek başı 10.000/250/200; tekrar limitleri 10.000/250/200; günlük 50.000/1.250/1.000; max ödeme genel/tekli 100.000/2.500/2.000; canlı ve canlı tekli 100.000/1.000/800; max seçim 20; min kupon oranı 1.01; max kupon oranı 500; min oran 1.01; max oran 30; canlı kapanış dakikası 85; bahis bozdurma kapalı; oyuncu iptal 0.
- Panelde Kupon Sorgulama: kupon no ile, sadece kendi ağacı, başka dal 404.
- Açık soru (Emre'ye): "tekrar limiti 3000" adet mi tutar mı; "85" maçın dakikası mı (tutar ve dakika varsayıldı).

## 6. Tasarım

### Oyuncu sitesi
- **Tema sistemi:** Renkler tek yerde, `resources/css/app.css` içinde `--site-*` token'ları (bg, bg-deep, panel, panel-2, line, line-strong, text, text-2, muted, on-accent, second, live, gold) + `--accent`. `<html data-theme="classic|neon|desert">`; `html[data-theme]{color-scheme:dark}`. View'larda sabit hex yok (yalnız "kazandı" yeşilleri ve tema önizleme renkleri).
  - **Classic Casino (varsayılan):** zemin #0E0E10, panel #18181C, vurgu altın #F5B83D, ikincil #C8102E.
  - **Neon Strip:** zemin #0B0A1A, vurgu #FF2E88, ikincil #22D3EE.
  - **Desert Night:** zemin #140D14, vurgu #FF7A2F, ikincil #B45CFF.
  - Seçim zinciri (`site_theme()`): oyuncunun `users.theme` → süperadminin `users.theme` (ağacın varsayılanı) → classic. Ziyaretçi classic. Oyuncu Hesabım'daki Tema kartından seçer (Varsayılan + 3 tema), süperadmin panelde "Site teması" (`/panel/theme`, sadece süperadmin; diğerleri 404). Not: Eloquent `->value()` cast uygular (enum döner) → ham değer için `->toBase()->value()`.
- **Ana sayfa (`/`) = vitrin** (yöneticiler `/panel`'e yönlenir). `HomeFeed` servisi + `site/home.blade.php`:
  - Banner: "GÜNÜN MAÇI", başlık (`home.hero_title`, DE "Willkommen bei Wegas"), butonlar, sağda günün maçı + 1X2 oranları (tıklayınca kupona). Mobilde sade (paragraf gizli, takımlar tek satır).
  - **Günün oyunları:** popüler slotlardan tarihe göre (crc32 tohum) her gün değişen 6 oyun.
  - Hızlı erişim: Canlı bahis (canlı maç sayısı), Spor bülteni (bugünkü maç), Slot (oyun sayısı), Canlı casino (sayı ya da "Yakında").
  - Spor bloğu: Yaklaşan maçlar (5), Günün popüler maçları (son 24 saatte en çok kupona eklenen, oranlı), **Günün kombinesi** (otomatik: başlamasına >10 dk, askıda değil, 1.25–1.90 favori ya da 2.5 Üst, maç başına bir seçim, 4 seçim, oran tavanı kontrolü; `POST /sport/combo` tek istekte kupona ekler).
  - Popüler slotlar şeridi (günün oyunları hariç), **Son kazananlar** (defterden `type=win`, sadece izleyenin süperadmin ağacı, maskeli kullanıcı adı; ziyaretçide gizli), footer (18+ notu).
  - Bülten kuralı ortak: `App\Services\Sport\Bulletin::query()` (spor sayfası ve ana sayfa aynı).
- **Giriş:** `/login` iki sütunlu (solda marka alanı, sağda TR|EN|DE|AR segment + form). Header'daki "Giriş" modal açar (`<dialog id="login-dialog">`, mobilde alttan); hatalı girişte modal hata ile yeniden açılır. Şifre göster/gizle, "Şifrenizi bayinizden isteyebilirsiniz" notu. Ortak parça `auth/_form`.
- **Kuponlarım:** sıkı kupon kartları (numara + durum rozeti, tutar/oran/olası kazanç kutuları, seçim satırları "Takım - Takım · Market · Seçim · saat — oran + rozet"), masaüstünde 2 sütun. Detay: solda sabit özet (iptal formu), sağda seçim listesi. Canlı yenileme işaretleri (`data-live-selection/-text/-pulse`) korunuyor. Parçalar: `sport/_selection_row`, `_coupon_status`, `_coupon_metrics`; `_badge` `dark` parametresiyle koyu ton; `_coupon_facts` `compact` modu.
- **Slot sayfası:** `casino_games.vendor` (görsel adresindeki `/game_pic/<slug>/` → `App\Support\Vendors`; 18 sağlayıcı: pp 675, amusnet 296, bng 295, egt 223, cq9 160, jili 144, hab 127, pg 120, tada 115, 3oaks 91, hacksaw 40…). Sol menü sağlayıcılar **popülerliğe göre** (son 30 gün `game_rounds` oynama → popüler oyun sayısı → öncelik listesi), yanında oyun sayısı; mobilde yatay çipler. Sekmeler Tümü/Popüler/Favoriler/Son oynananlar, arama, toplam sayı. Yeni oyun kartı: kare görsel, üzerine gelince "Oyna", gerçek sağlayıcı adı; ziyaretçide giriş modalı.
- Üye dil seçici göremez (sadece giriş sayfasında).
- Referans rakip: elanobet.com (iki katlı header + ikonlu pembe menü şeridi, vitrin ana sayfa, sanal bahis sekmesi).

### Yönetim paneli
- Açık tema back-office. Blade bileşenleri: `x-panel.card`, `x-panel.stat`, `x-panel.table` (masaüstünde tablo, mobilde kart), `x-panel.filter-bar`, `x-panel.form-row`, `x-panel.empty`, `x-panel.badge`, `x-panel.sticky-actions`, `x-panel.chart`.
- Masaüstünde sabit sol menü (rol bazlı). 390px'te üst bar + hamburger drawer + alt menüde 4 kısayol: Owner (Özet, Kullanıcılar, Kuponlar, Spor veri), Süperadmin/Bayi (Genel Bakış, Kullanıcılar, Tüm Kuponlar, Hesap hareketleri).
- **Mobil işlem kalıbı:** Kısa işlemler (bakiye, şifre, hızlı işlemler) **alttan açılan panel** (masaüstünde ortada); inceleme ekranları (kupon detayı vb.) **ayrı sayfa** (kendi URL'i, geri tuşu, rota bazlı 404).
- Spor Ayarları: gruplu kartlar, akordeon, tam sayı tutarlar kuruşsuz, birim input içinde, alan bazında hata, sticky kaydet çubuğu.

### Kullanıcılar sayfası (27.09, owner/süperadmin/bayi ortak)
- Sunucu: 50'şerlik sayfalama; filtreler `q`, `status`, `funded` (bakiyesi olan), `idle` (7+ gün girmeyen), `from/to`; sıralama `sort=username|balance|login`; özet (toplam, aktif, para birimi bazında toplam bakiye). Gereksiz `children` eager load kaldırıldı.
- Mobil: 3 özet kutusu, arama + sıralama + "+ Yeni", yatay filtre çipleri, **tek satırlık liste** (durum noktası, ad, son giriş, alt kullanıcı sayısı, bakiye). Satıra dokununca **işlem paneli**: Ekle / Çıkar / Pasif-Aktif et / Şifre sıfırla / Düzenle / Kuponları (üye) / Alt kullanıcılar. "Daha fazla göster" sonraki sayfayı fetch ile ekler.
- Masaüstü: tablo (bayide Rol kolonu yok), satırda Ekle/Çıkar/İşlemler, altta sayfa numaraları.
- **Bakiye paneli:** Ekle/Çıkar sekmeli; mevcut bakiye, "Sizin bakiyeniz" (yüklemede, owner hariç), "İşlem sonrası" önizlemesi; tutar `1.000,50` / `1000.50` / `1.000` kabul, sunucuya normalize gider; çekimde üye bakiyesi, yüklemede kendi bakiyesi aşılırsa uyarı + kilit. Sunucu kuralları nihai.
- Şifre sıfırlama sonucu sayfanın üstünde tek seferlik kartta, "Kopyala" butonu (HTTP'de clipboard API yok, textarea yedeği var).

### İstatistik ve dashboard'lar (Faz B–D)
- `daily_stats`: `stat_date` (süperadminin saat dilimi), `user_id` (süperadmin/bayi), `currency`, `product`, `turnover`, `payout`, `ggr`, `bet_count`, `active_players`, `new_players`. Tekil: stat_date + user_id + product.
- Bir üye bahsi iki satıra yazılır (bayi + süperadmin). Owner sadece süperadmin satırlarını toplar. Kur çevrilmez.
- Kurallar (`StatRules`): sport/slot/live_casino adjustment kayıtları payout'a işaretiyle girer; transfer/adjustment/bonus girmez. İade/iptal gerçekleştiği güne yazılır. Job 10 dk'da bir bugün + dün; `sport:stats-backfill` idempotent.
- Owner paneli: TRY/USD/EUR sekmeleri, 30 günlük grafikler, ürün halkası, operasyon kutusu, süperadmin listesi. Süperadmin paneli: kendi ağacı, bayi GGR, bayi detayı. Bayi paneli iskelet.

### Demo
- `demo:reset` (sadece production dışı): migrate:fresh → DemoSeeder → sport:sync-leagues/fixtures/odds → GoldPalace oyun senkronu (hata verirse uyarıyla devam) → DemoHistorySeeder → stats-backfill → wallet:verify. Süre ~3–4 dk. Owner şifresi `.env` `OWNER_PASSWORD`'dan.
- DemoHistorySeeder: senkronlanmış gerçek maçlardan havuz; her süperadmin için günde 8 bitmiş demo maç (`api_id` 9.000.000.000.000+, `score_source=manual`). Her üye günde bir kupon (~%60 tekli), sonuç `SelectionEvaluator` ile. Son sonuç: 960 kupon, 1.530 seçim, 400 kazanan / 560 kaybeden; GGR spor ~%10, slot ~%5–6, canlı ~%3–4.
- WalletService `occurredAt` parametresi sadece production dışı (üretimde `wallet.occurred_at_forbidden`).

## 7. Faz durumu

| Faz | İçerik | Durum |
|---|---|---|
| 1 | İskelet, i18n, lang:check, kurallar | ✅ |
| 2 | Hiyerarşi, giriş, panel, üst zincir gizliliği | ✅ |
| 3 | Cüzdan, defter, owner kredi modeli, wallet:verify | ✅ |
| 4a | Oyuncu sitesi, casino altyapısı, GoldPalace | ✅ (1GameX beklemede) |
| 6a–6c | Spor verisi, kupon, limitler, otomatik sonuçlandırma, live-sync | ✅ |
| Panel A–E | Tasarım sistemi, daily_stats, owner/süperadmin panelleri | ✅ |
| Yetkiler/Güvenlik | Bayi NCS modeli, test koruması, cüzdan deadlock + sıra no, EnsureAccountActive | ✅ |
| Kullanıcılar | Bakiye alt paneli, sayfalama/filtre/hızlı işlem/şifre, mobil liste | ✅ (024714a) |
| Tema | Token'lar (2614bc1), seçim zinciri + Wegas adı (2509fe4) | ✅ |
| Oyuncu sitesi yenileme | Ana sayfa vitrini + günün kombinesi, giriş sayfası/modal, Kuponlarım, günün oyunları, mobil banner, slot sayfası (vendor) | ✅ (27.09, commit'ler pushlandı) |
| Altyapı | wegas11.com SSL, IP callback düzeltmesi, casino 0 kazanç düzeltmesi | ✅ |
| Wegas Spor | Tipo iframe, NCS köprüsü (Wegas ucu + NCS ucu + sayfa/menü) | ✅ |
| 6e-1 | Fenix maç önü senkronu (futbol, 7 market, market_uid, mbs), API-Football zamanlamadan çıktı, demo:reset Fenix ile | ✅ (commit bekliyor olabilir, git log'a bak) |
| Canlı casino | 1GameX sağlayıcısı (senkron, oyun açma, callback) | Yarım |
| 6e-2 / 6e-3 | Fenix sonuçlandırma (resultApi) / Fenix canlı bahis | Sırada |
| Header | İki katlı header + ikonlu menü şeridi + mobil alt menüde Ana sayfa | Sonra |
| 6d-1 | Maç Merkezi (API-Football yerine Fenix/betradar_id ile yeniden düşünülecek) | Sonra |
| 5 | Raporlar, komisyon, mutabakat | Sonra |

## 8. Açık işler (öncelik sırasıyla, 30.09 akşam)

> **02.10 güncel öncelik:** Bölüm 0d faz tablosu (Faz 2 → 3 → 4 → 5) aşağıdaki listeden önce gelir. Aşağıdakiler (RomaSpin gerçek oyun testi, GoldPalace `wegas_try`, anahtar yenileme, yedek) hâlâ açık.

1. **NCS VIP'e RomaSpin:** Aynı yapı (canlı casino + mini + GoldPalace öncelikli slot harmanlama + gece senkronu). RomaSpin panelinde `master-NCS` altına `ncsvip` alt agent'ı (Operatör, Seamless, TRY); 91.229.239.212 o hesap için de whitelist'te mi, RomaSpin'e teyit ettir.
2. **GoldPalace `wegas_try` onayı** → `.env` GoldPalace bilgileri + callback `https://wegas11.com/api/casino/goldpalace/callback`, eski `winoxbet_try` bırakılır.
3. **Gerçek oyun testi (RomaSpin):** Volkan → test bayi → test üye (100 ₺). Aviator + bir Evolution masası + bir RomaSpin slotu. Logdan `amount` işareti ve iptal şekli doğrulanacak; `wallet:verify`.
4. **RomaSpin'den cevap beklenen:** `mini-pragmatic` zaman aşımı, Novomatic Lock 'N' Win serisi. `wegas` saat dilimi Istanbul mu, kontrol.
5. **GoldPalace USD/EUR desteği** (süperadmin çok para birimli). RomaSpin'de TRY dışı üye zaten engelli; GoldPalace için de destek yoksa USD/EUR üyede oyun açılışı engellenmeli.
6. Canlı doğrulama (para birimi zinciri): owner → Volkan USD, Volkan → USD bayi, `wallet:verify`.
7. Görsel ısıtma logu (`storage/logs/warm-images.log`); RomaSpin'in 1.800+ yeni görseli ilk açılışta üretilecek, gerekirse `casino:warm-images` tekrar.
8. Süperadmin raporlarında tutarların para birimine göre ayrı toplanması; sol üst kartta çok para birimli gösterim.
9. **Anahtar yenileme:** GoldPalace token, NCS köprü anahtarı (iki sunucuda), RomaSpin `wegas` secret'ı ve agent şifreleri sohbette/ekran görüntüsünde göründü.
10. **Cloudflare turuncu bulut** + gerçek IP (köprü IP kısıtları, RomaSpin whitelist'i etkilenir: sunucu çıkış IP'si değişmez, sorun olmamalı ama kontrol).
11. **Günlük otomatik DB yedeği** (sunucu dışına), log rotasyonu (`casino.romaspin.callback` tam payload logluyor, log hızlı büyür).
12. GoldPalace callback'indeki `throttle:120,1` trafik artınca dar kalabilir.
13. API-Football kodunun ve `demo:reset`'in kaldırılması; kendi spor API'si → uluslararası açılış.
14. **NCS VIP:** GameXCallbackController imza doğrulaması.

### Çözülen önemli hatalar (ders)
- HTTP'de `crypto.randomUUID()` ve `navigator.clipboard` yok: idempotency anahtarları sunucuda üretilir; kopyalama için textarea yedeği.
- Formlarda hata asla sessizce yutulmaz. Testte `assertRedirect()` hatayı gizleyebilir; işlem başarısı `assertSessionHasNoErrors()` ile doğrulanır.
- Artisan'ı root ile çalıştırmak storage izinlerini bozar: her zaman `sudo -u www-data`.
- Config cache açıkken `env()` kod içinde null döner; ayrıca **testler gerçek DB'ye bağlanır ve `RefreshDatabase` onu siler** (27.09'da `ncsvip` boşaldı, demo:reset ile geri geldi). TestCase koruması eklendi.
- Ertelenen maçta `starts_at` kayar: aday seçimi ve 48 saat kuralı `kickoff_at` snapshot'ından, void kararı `fixture.timestamp`'ten.
- `migrate:fresh` sağlayıcı oyunlarını ve spor verisini de siler; sıfırlama komutları senkronları da çalıştırmalı. Seeder içinden seeder çağırmak sırayı bozar.
- live-sync'i sadece kupona bağlamak canlı bülteni bayat bırakır.
- Olmayan bir unique anahtarı `FOR UPDATE` ile okumak gap lock alır; paralel INSERT'ler deadlock (1213) üretir. Önce ana satırı kilitle, sonra kilitsiz oku.
- UUID birincil anahtar + saniye hassasiyetli `created_at` ile sıralama güvenilmez; sıra gereken yerde açık sıra numarası kullan.
- Laravel `AuthenticateSession` oturumda tek şifre özeti tutar; aynı oturumda kullanıcı değişince yanlış alarm verir. Kullanıcı id'sine bağlı anahtar kullan.
- SQLite ham sorguda ondalığı `40` döndürür, MariaDB `40.00`; testte tutarlar `number_format` ile karşılaştırılır.
- Bash'te çift tırnak içindeki `!` geçmiş kısayolu sayılır (`event not found`); komutlarda kullanma.
- Yetki modeli Tipo panelinden kopyalanınca NCS kurallarıyla çelişti (bayi limit/iptal yetkisi). Referans NCS VIP'tir.
- İki agent aynı dizinde paralel çalışınca biri diğerini bekleme döngüsüne girebilir; işler sıralı verilir.
- Eloquent `->value('kolon')` cast uygular (enum nesnesi döner); ham değer karşılaştırması için `->toBase()->value()`.
- Blade dosyasında `<html[^>]*>` gibi düzenli ifade `app()->getLocale()` içindeki `>`'da kesilir; etiket satırını satır bazında düzenle.
- Kabukta dosya listesi boş kalırsa `grep` stdin bekler ve komut takılır; boş değişkenle grep çalıştırma.
- Türkiye mobil hatlarında (özellikle Vodafone) bahis alan adları ve sağlayıcı CDN'leri yavaşlatılıp engellenebiliyor; çözüm Cloudflare proxy (IP gizleme), görselleri kendi alan adından sunma ve yedek alan adı.
- GoldPalace oyunlarının gerçek sağlayıcısı API'de ayrı gelmiyor; görsel adresindeki `/game_pic/<slug>/` güvenilir kaynak.
- certbot `--nginx --redirect` 80 portundaki bloğu `return 404` yapar; IP üzerinden gelen sağlayıcı callback'leri kesilir. SSL sonrası IP/HTTP erişimini mutlaka kontrol et.
- Sağlayıcılar kayıp turda 0 tutarlı "win" gönderir; cüzdan servisi 0'ı reddettiği için ayrıca ele alınmalı.
- Model `$fillable` listesinde olmayan alan `updateOrCreate`'te sessizce atlanır (vendor, market_uid, mbs).
- `Http::fake()` testte ikinci kez çağrılınca ilk tanım geçerli kalır; `Http::swap(new Factory())` ile sıfırla.
- `wallet_transactions.product` DB enum; yeni ürün türü eklemek SQLite testlerinde tablo yeniden kurulumu ve trigger kaybı demek → mevcut ürün + idempotency önekiyle ayırt et.
- İki sunucu arasında çalışırken komutun hangi sunucuda koştuğunu (istem satırı) kontrol et; yanlış sunucuda çalışan komut dosya bulamaz ya da yanlış `.env`'e yazar.

- **Canlıda açılan her `.env` ayarı `phpunit.xml`'de test için sabitlenmeli** (PANEL_DOMAIN eklenince 30 panel testi 302 ile düştü).
- Laravel middleware öncelik listesinde auth **`AuthenticatesRequests` arayüzüyle** kayıtlı; `prependToPriorityList(before: AuthenticatesRequests::class, ...)`. Somut `Authenticate` sınıfı verilirse ekleme sessizce etkisiz kalır.
- Kabukta `{ ...; exit; }` mevcut oturumu (SSH) kapatır; komutlarda `exit` kullanma, kontrolü Python/if ile yap.
- SSL'e alan adı eklerken `certbot certonly ... --expand` kullan (nginx dosyasını değiştirmez).
- Tek sütunlu grid'de uzun metin sütunu genişletip sayfayı yana kaydırır → `grid-cols-1` (`minmax(0,1fr)`).
- Windows `scp`'de hedef yolun sonundaki `\"` tırnağı kaçırır; sondaki ters eğik çizgi kullanılmaz. `scp` Yusuf'un bilgisayarında çalışır (`winoxbet` takma adı orada).
- Yeni test sınıfında `RefreshDatabase` unutulursa tablolar yok (500).
- Fenix: biten maç `liveEvents`'ten düşer; skor için `resultbot`.

- **Rota önbelleği testleri de etkiler:** rota değiştiyse testten önce `route:clear` (aksi halde yeni rota 404).
- Laravel JSON'da `94.0` → `94` yazar (`JSON_PRESERVE_ZERO_FRACTION` yok); testte tutarı float değil sayı olarak karşılaştır.
- Tinker'da uzun kod: `sudo -u www-data php artisan tinker <<'PHP' … PHP` (tırnak kaçışı yok). Sonuç sayfalayıcıya düşmesin diye kodu `(function () { … })();` içine al; sayfalayıcıdan `q` ile çıkılır. `--execute="…"` içinde karmaşık tırnaklar kabuğu `>` bekleme durumuna sokar (Ctrl+C).
- Sağlayıcı oyun adları sağlayıcılar arasında farklı yazılabilir (™, Roma rakamı, yazım hatası); isim eşleştirmesinde "deluxe" gibi sürüm farkları **silinmez**.
- Aynı API anahtarıyla birden fazla sistem çalışmaz: agent başına tek callback adresi var ve `np_<id>` kodları çakışır → her platform için alt agent.

## 9. Hesaplar

- **Canlıda sadece owner (#1)** var; şifre go-live'da yenilendi (Yusuf'ta). Yeni hesaplar **#1000**'den başlar. Demo hesapları (demo-tr vb.) **silindi**.
- Yöneticiler sadece `https://panel.wegas11.com`, üyeler sadece `https://wegas11.com` üzerinden girer.

## 10. Son durum

- Testler: **212 geçti** (02.10; `GameBlockTest` dahil) (sunucuda test için önce `config:clear`, rota değiştiyse `route:clear`; sonra önbellekleri geri kur). `lang:check`: **612 anahtar**. Son commit: `4380d5b` (Faz 2: Siteyi göster kaldırıldı).
- Zamanlayıcı: `sport:fenix-prematch` 5 dk, `sport:fenix-results` 10 dk, stats 10 dk, `sport:translate` saatlik, `casino:sync romaspin` 01:30 UTC, `wallet:verify` 03:30 UTC.
- Oyunlar: Slot 3.911 (GoldPalace + RomaSpin), Canlı Casino 223 (RomaSpin), Mini Oyunlar 24 (RomaSpin). 1GameX pasif.
- Canlı: `wegas11.com`, `panel.wegas11.com`; RomaSpin callback `https://wegas11.com/api/casino/romaspin/api/{balance|transaction|batch-transaction}` (401/2 kontrolleri tamam); GoldPalace callback hâlâ IP/HTTP.
- Hesaplar: owner (#1), süperadmin **Volkan** (TRY/USD/EUR). Üye yok. Yeni hesaplar #1000+.
- Son push: Wegas `4380d5b`; NCS VIP `b460f20`.
