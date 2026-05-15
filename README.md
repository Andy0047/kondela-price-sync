# Kondela → Prodboard price sync

Synchronizácia cien z B2B XML feedov do Prodboard cenníkov.

## Požiadavky

- PHP 7.4+ (curl, simplexml, json)
- Súbor `.env` s `PRODBOARD_COMPANY` a `PRODBOARD_PRIVATE_KEY` (pozri `.env.example`)

## Krajiny

| Krajina   | Skript            | Feed URL                                              | Cenník Prodboard | XML pole  | Cache súbor                    |
|-----------|-------------------|-------------------------------------------------------|------------------|-----------|--------------------------------|
| SK/EUR    | `update_price.php`| `https://b2b.kondela.sk/feed/kuchyna_jedalen.xml`     | `price`          | `MOC_EUR` | `kondela_price_cache.json`     |
| CZ        | `update_czk.php`  | `https://b2b.kondela.sk/feed/kuchyna_jedalen_cz.xml`  | `price_czk`      | `MOC_CZ`  | `kondela_price_cache_cz.json`  |
| RO        | `update_ron.php`  | `https://b2b.kondela.sk/feed/kuchyna_jedalen_ro.xml`  | `price_ron`      | `MOC_RO`  | `kondela_price_cache_ron.json` |
| HU        | `update_huf.php`  | `https://b2b.kondela.sk/feed/kuchyna_jedalen_hu.xml`  | `price_huf`      | `MOC_HU`  | `kondela_price_cache_huf.json` |
| HR        | `update_hr.php`   | `https://b2b.kondela.sk/feed/kuchyna_jedalen_hr.xml`  | `price_hr`       | `MOC_HR`  | `kondela_price_cache_hr.json`  |

Spoločný log: `kondela_price_sync.log`

## Spustenie

```bash
php update_price.php   # Slovensko (EUR)
php update_czk.php     # Česko
php update_ron.php     # Rumunsko
php update_huf.php     # Maďarsko
php update_hr.php        # Chorvátsko
```

## Premenné prostredia (voliteľné)

Každý `update_*.php` wrapper nastaví predvolené hodnoty cez `putenv`. Môžu sa prepísať v `.env` alebo pri spustení:

| Premenná               | Popis |
|------------------------|-------|
| `SYNC_SOURCE_URL`      | URL XML feedu |
| `SYNC_CACHE_FILE`      | JSON cache posledných cien |
| `SYNC_LOG_FILE`        | Log súbor |
| `SYNC_PRICE_LIST_CODE` | Kód cenníka v Prodboard API |
| `SYNC_DRY_RUN`         | `1` = len simulácia, bez odoslania do API |
| `SYNC_FORCE_ALL`       | `1` = odoslať všetky ceny z feedu (ignoruje cache) |

### Prvý beh Chorvátska

`update_hr.php` má `SYNC_FORCE_ALL=1` pre úvodný import všetkých cien. Po úspešnom behu nastav späť na `0`, aby ďalšie behy posielali len zmeny.

## Cron

Príklad `crontab` (uprav cestu k PHP a projektu):

```cron
# Kondela price sync – každý deň o 03:00 (Europe/Bratislava)
0 3 * * * cd /path/to/kondela && /usr/bin/php update_price.php >> /var/log/kondela_cron.log 2>&1
5 3 * * * cd /path/to/kondela && /usr/bin/php update_czk.php   >> /var/log/kondela_cron.log 2>&1
10 3 * * * cd /path/to/kondela && /usr/bin/php update_ron.php  >> /var/log/kondela_cron.log 2>&1
15 3 * * * cd /path/to/kondela && /usr/bin/php update_huf.php  >> /var/log/kondela_cron.log 2>&1
20 3 * * * cd /path/to/kondela && /usr/bin/php update_hr.php   >> /var/log/kondela_cron.log 2>&1
```

Kompletný vzor: `crontab.example`

## Logy a cache

- Logy a cache sú v `.gitignore` a na serveri sa nevytvárajú z repozitára.
- Pri chybe auth skript končí s kódom `4`, pri fetch XML `2`, pri parsovaní `3`.
