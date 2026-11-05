# dbmig – masowa migracja baz MySQL stron na nową instancję (hosting współdzielony, DirectAdmin)

<p align="center">
  <a href="https://github.com/sponsors/nieprzecietny">
    <img src="https://img.shields.io/badge/%E2%9D%A4%EF%B8%8F%20%20Podzi%C4%99kuj%20zostaj%C4%85c%20sponsorem-ff2d6b?style=for-the-badge&logo=githubsponsors&logoColor=white" alt="Podziękuj zostając sponsorem" height="48">
  </a>
  <br>
  <sub>Zaoszczędziło Ci to parę godzin klikania w panelu? Kliknij wyżej. / Saved you a few hours? Sponsor the author.</sub>
</p>

[![Release](https://img.shields.io/github/v/release/nieprzecietny/dbmig?label=release)](https://github.com/nieprzecietny/dbmig/releases/latest) [![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Jeden skrypt PHP uruchamiany **na serwerze przez SSH**, który dla wszystkich stron na koncie hostingowym:

1. znajduje pliki konfiguracyjne i wyciąga z nich host / nazwę bazy / login / hasło (WordPress, Joomla, Drupal, PrestaShop, OpenCart, Magento 2, Moodle, Laravel, Symfony, własne skrypty PHP),
2. generuje dane nowych baz i zakłada je w panelu (API DirectAdmin albo wtyczka hostingu),
3. robi `mysqldump` starej bazy, importuje do nowej, porównuje tabele i liczbę wierszy,
4. podmienia dane dostępowe w plikach konfiguracyjnych (backup, `php -l`, ponowny odczyt, test HTTP strony),
5. przy błędzie po zmianie pliku **sam przywraca backup**.

Powstał do migracji z „legacy” MariaDB 10.6 na MariaDB 10.11 na hostingu cyber_Folks (procedura: <https://cyberfolks.pl/pomoc/migracja-bazy-danych-na-nowa-instancje/>), ale działa na każdym koncie z układem `~/domains/<domena>/public_html` i dostępem do `mysqldump`/`mysql`.

> **English summary.** `dbmig.php` is a single-file PHP CLI tool for shared hosting (DirectAdmin layout) that scans all sites on an account, extracts DB credentials from their config files (WordPress, Joomla, Drupal, PrestaShop, OpenCart, Magento 2, Moodle, Laravel, Symfony, custom PHP), creates the new databases through the panel API, dumps/imports/verifies each database and rewrites the config files with backups, syntax check, read-back and an HTTP probe with automatic rollback. Messages and docs are in Polish.

## Zanim zaczniesz: dane do uzupełnienia

W instrukcji poniżej występują znaczniki, które zastępujesz swoimi wartościami:

| znacznik | co to jest | gdzie to znaleźć |
|---|---|---|
| `<KONTO>` | login do hostingu = prefiks nazw baz (`<KONTO>_nazwa`) | login SSH / panelu |
| `<SERWER>` | adres panelu DirectAdmin z portem, np. `https://sXX.cyber-folks.pl:2223` | pasek adresu po zalogowaniu do panelu |
| `<HOST_NOWEJ_INSTANCJI>` | host i port nowej instancji MySQL, np. `127.0.0.1:3308` | panel → Zarządzanie MySQL → Utwórz bazę → lista „Instancja MySQL” (w nawiasie) albo widok bazy („Połączenie lokalne”) |
| `<NR_INSTANCJI>` | numer opcji nowej instancji w tym formularzu (np. `2`) | jak wyżej; `1` to zwykle stara instancja |
| `<DOMENA>` | domena strony, np. `example.pl` | id wiersza w `mapping.conf` zaczyna się od domeny |

Nie wpisuj `localhost:port` jako hosta nowej bazy: PHP zignoruje port i połączy się socketem ze starą instancją. Używaj `127.0.0.1:port`.

## Co skrypt gwarantuje

- **Nigdy nie modyfikuje ani nie kasuje starej bazy** – tylko ją czyta. Stare bazy kasujesz ręcznie w panelu, gdy wszystko działa.
- Nic nie robi bez `action = migrate` w `mapping.conf` **i** bez `--site ...` / `--all`.
- `--dry-run` pokazuje dokładnie, co by się stało (łącznie z każdą zmienianą linią), nie zmieniając niczego.
- Przed zmianą każdy plik trafia do `dbmig-work/backups/<id>/<data>/`. `rollback --site X` przywraca.
- Zatrzymuje się na pierwszym błędzie (chyba że `--continue-on-error`).
- Hasła nie trafiają do linii poleceń (`ps`), do logu ani na ekran (maskowane; `--show-secrets` odkrywa).
- Stan (`dbmig-work/state.json`) pozwala przerwać i wznowić: zrobione zrzuty/importy nie są powtarzane.

## Wymagania

- PHP ≥ 7.2 CLI z rozszerzeniami `tokenizer` i `mysqli` (na hostingu zwykle `php`, ew. `php82`, `php83`),
- `mysqldump` i `mysql` w PATH (albo `--mysqldump-bin=...`, `--mysql-bin=...`),
- `curl` (rozszerzenie PHP lub program) do testu HTTP i API panelu.

## Instalacja

```bash
mkdir -p ~/dbmig && cd ~/dbmig          # NIE w public_html
curl -LO https://github.com/nieprzecietny/dbmig/releases/latest/download/dbmig.php
php dbmig.php --help
```

Bez `curl` na serwerze: pobierz `dbmig.php` z [Releases](https://github.com/nieprzecietny/dbmig/releases) i wgraj przez SFTP.

Katalog roboczy `dbmig-work/` (zrzuty, backupy, mapping z hasłami, logi, stan) powstaje obok skryptu z prawami `700` i jest pomijany przy skanowaniu. Skrypt odmawia pracy, gdy katalog roboczy leży w `public_html`.

## Procedura krok po kroku

### 1. Skan

```bash
php dbmig.php scan
```

Domyślnie przeszukuje `~/domains`. Inny katalog: `--root=/sciezka`. Wynik:

- `dbmig-work/inventory.csv` i `inventory.json` – strona, system, plik, host, baza, user, **hasło**, prefix, czy połączenie działa, wersja serwera, liczba tabel, rozmiar,
- `dbmig-work/mapping.conf` – plan migracji do uzupełnienia,
- tabela na ekranie (hasła zamaskowane).

Przejrzyj kolumnę „uwagi”: pliki z wartościami dynamicznymi, stare kopie stron (`public_html_bak`, `public_html-6`), pliki `.env` z Dockera (host `db`), `.env.local` nadpisujący `.env`, strony używające `mysql_*` (działają tylko na PHP 5). Ponowny `scan` nie nadpisuje tego, co już wpisałeś.

Porównaj liczbę znalezionych baz z listą w panelu. Bazy bez znalezionej konfiguracji to zwykle osierocone bazy po starych stronach albo konfiguracja poza `~/domains` (`--root`).

### 2. Dane nowych baz

```bash
php dbmig.php plan --all --new-host=<HOST_NOWEJ_INSTANCJI>
```

Wpisuje do `mapping.conf` nazwy (`stara_nazwa_new`, inny sufiks: `--suffix=...`), userów, losowe hasła (tylko znaki bezpieczne w URL i `.env`) oraz `action = migrate`. Jedna stara baza używana przez kilka stron dostaje jedną nową bazę. Wiersze, których stare dane nie działały przy skanie, albo z wartościami dynamicznymi, dostają `action = manual`.

Teraz otwórz `mapping.conf` i ustaw `action = skip` dla martwych kopii i plików, których nie chcesz ruszać.

### 3. Założenie baz

Przez wtyczkę cyber_Folks:

```bash
php dbmig.php create-db --list --da-preset=cyberfolks --da-url=<SERWER>
php dbmig.php create-db --all --dry-run --da-preset=cyberfolks --da-instance=<NR_INSTANCJI> --da-url=<SERWER>
php dbmig.php create-db --all --da-preset=cyberfolks --da-instance=<NR_INSTANCJI> --da-url=<SERWER>
```

Skrypt pyta o hasło do panelu (to samo, co do DirectAdmin; alternatywnie `DA_PASS=...` w środowisku albo klucz z DirectAdmin → Login Keys). Login do panelu = `<KONTO>` (domyślnie z `$USER`/`$HOME`; inaczej `--da-user=<KONTO>`). Adres panelu zapamiętuje w `state.json`, więc `--da-url` podajesz raz. Po założeniu każdej bazy łączy się do niej i pokazuje wersję serwera: **jeśli jest taka sama jak starej bazy, baza powstała na starej instancji** (zły numer instancji). W opisie bazy w panelu wpisuje domeny stron i starą nazwę (`--da-comment` zmienia szablon).

Inny panel / brak API: załóż bazy ręcznie w panelu wg tabeli z `plan` (`--dry-run` ją wypisuje). Natywne API DirectAdmin (`CMD_API_DATABASES`) działa bez presetu, jeśli hosting je włączył. Wtyczkę innego hostingu można obsłużyć przez `--da-cmd` i `--da-fields` (sekcja „Inne panele”).

### 4. Sprawdzenie (nic nie zmienia)

```bash
php dbmig.php check
php dbmig.php show --site <DOMENA>       # które linie w których plikach zostaną zmienione
```

Łączy się do każdej starej i nowej bazy, sprawdza, że nowa jest pusta, że pliki da się bezpiecznie podmienić, że strona odpowiada, że jest miejsce na dysku, porównuje wersje serwerów. Błąd = `migrate` nie ruszy.

### 5. Migracja

```bash
php dbmig.php migrate --site <DOMENA> --dry-run
php dbmig.php migrate --site <DOMENA>
php dbmig.php migrate --all                   # zmigrowane wiersze są pomijane
php dbmig.php status
```

`--site` przyjmuje domenę (wszystkie wpisy tej domeny), id wiersza, kilka wartości po przecinku albo wzorzec (`'sklep*'`). `--yes` pomija pytanie o potwierdzenie.

Dla każdej bazy: `mysqldump --single-transaction --quick --hex-blob --routines --triggers --events` do `dbmig-work/dumps/`, w locie usuwanie `DEFINER=` (import bez SUPER) i podmiana odwołań `` `stara_baza`. `` w widokach/triggerach/procedurach; import `mysql` do pustej nowej bazy; weryfikacja listy tabel (brak tabeli = stop) i liczby wierszy (różnica = ostrzeżenie, bo strona żyje; `--strict-verify` = błąd). Potem dla każdego pliku: backup → podmiana samych literałów → `php -l` → ponowny odczyt i porównanie → po `--http-delay` sekund pobranie strony i szukanie komunikatów o błędzie bazy / HTTP 5xx → przy problemie automatyczne przywrócenie backupu.

### 6. Po migracji

- Sprawdź strony w przeglądarce: tam, gdzie test HTTP dawał 404/403 (API, podkatalogi), wejdź na prawdziwe adresy; w WordPressach zaloguj się do wp-admin.
- Symfony: `php bin/console cache:clear` w katalogu aplikacji (albo `rm -rf var/cache/prod`).
- Gdy coś nie działa: `php dbmig.php rollback --site <DOMENA>` wraca na starą bazę (dane zapisane po migracji zostają w nowej bazie!).
- Po kilku dniach: skasuj bazy „legacy” w panelu, usuń `dbmig-work/dumps` (dane) i zabezpiecz lub usuń `mapping.conf` oraz `inventory.*` (hasła).

### Gdy trzeba zmienić hasło nowej bazy (`repass`)

```bash
php dbmig.php repass --all --da-preset=cyberfolks --da-instance=<NR_INSTANCJI>
```

Zmienia hasło użytkownika nowej bazy przez formularz wtyczki panelu na bezpieczne w URL, weryfikuje połączenie i podmienia hasło we wszystkich plikach wskazujących na tę bazę (domyślnie tylko tam, gdzie to konieczne: `DATABASE_URL` z hasłem zawierającym znaki kodowane `%XX`; `--all-passwords` dla wszystkich). Przy błędzie przywraca pliki i poprzednie hasło. Potrzebne, gdy bazy założono ręcznie z dowolnymi hasłami – patrz „Pułapki”.

## mapping.conf

```ini
[defaults]
action = skip              # albo migrate dla wszystkich
new_host = 127.0.0.1:3308  # wspólny host nowej instancji
new_port =
new_name =
new_user =
new_pass =

[example.pl__public_html__wp-config.php]
cms = wordpress
file = /home/<KONTO>/domains/example.pl/public_html/wp-config.php
url = https://example.pl/
old_host = localhost
old_port =
old_name = <KONTO>_wp
old_user = <KONTO>_wp
old_pass = StareHaslo
action = migrate
new_host =
new_port =
new_name = <KONTO>_wp_new
new_user = <KONTO>_wp_new
new_pass = NoweHaslo
```

| pole | znaczenie |
|---|---|
| `action` | `skip` (nie ruszaj) / `migrate` (wszystko) / `db-only` (dump+import, konfigurację zmienisz ręcznie) / `config-only` (tylko podmiana konfiguracji, baza już przeniesiona) / `manual` |
| puste `new_*` | zostaje stara wartość |
| `-` | wyczyść pole (np. `new_port = -`) |
| `new_host` | może zawierać port `127.0.0.1:3307` albo socket `localhost:/sciezka/mysql.sock` |
| `[defaults]` | uzupełnia puste pola we wszystkich wierszach |
| `url` | adres do testu HTTP po zmianie; zgadywany z `domains/<domena>/public_html[/podkatalog]`; pusty = bez testu |
| wartości ze spacją na końcu/początku | ujmij w `"cudzysłowy"` (`\"`, `\\`) |

## Komendy

| komenda | co robi |
|---|---|
| `scan` | skanuje strony, tworzy `inventory.*` i `mapping.conf` |
| `plan` | generuje nazwy/userów/hasła nowych baz (`--suffix`, `--new-host`, `--action`, `--overwrite`) |
| `create-db` | zakłada bazy przez API/wtyczkę panelu (`--list`, `--da-preset`, `--da-instance`, `--da-url`, `--da-user`, `--da-pass`, `--da-comment`, `--da-cmd`, `--da-fields`, `--da-extra`) |
| `check` | sprawdza wszystko, nic nie zmienia |
| `show` | sloty w plikach i planowane zmiany |
| `migrate` | dump → import → weryfikacja → podmiana konfiguracji → test HTTP |
| `dump` / `import` / `verify` / `update-config` | pojedyncze etapy |
| `repass` | zmiana hasła nowej bazy przez panel + podmiana w plikach |
| `rollback` | przywraca pliki z backupu (`--backup=<znacznik>` dla konkretnego) |
| `clean-new` | usuwa tabele z NOWEJ bazy (do ponowienia importu); nigdy nie dotyka starej |
| `status` / `reset` | stan migracji / czyszczenie stanu |

Najważniejsze opcje: `--dry-run`, `--yes`, `--continue-on-error`, `--force`, `--redo`, `--no-http-check`, `--http-delay=<s>`, `--no-auto-rollback`, `--strict-verify`, `--no-routines`/`--no-events`/`--no-triggers`, `--keep-definer`, `--charset`, `--workdir`, `--root`, `--depth`, `--exclude`, `--no-custom`, `--show-secrets`, `--verbose`. Pełna lista: `php dbmig.php --help`.

## Obsługiwane konfiguracje

| system | plik | co jest podmieniane |
|---|---|---|
| WordPress (także `wp-config.php` katalog wyżej) | `wp-config.php` | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` |
| Joomla 3/4/5 | `configuration.php` | `$host`, `$db`, `$user`, `$password` |
| Drupal 7–10 | `sites/*/settings.php` | `$databases[...]`: `host`, `port` (dopisywany), `database`, `username`, `password` |
| PrestaShop 1.7 / 8 / 9 | `app/config/parameters.php` (lub `.yml`) | `database_host`, `database_port`, `database_name`, `database_user`, `database_password` |
| PrestaShop 1.6 | `config/settings.inc.php` | `_DB_SERVER_`, `_DB_NAME_`, `_DB_USER_`, `_DB_PASSWD_` |
| OpenCart | `config.php` + `admin/config.php` | `DB_HOSTNAME`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Magento 2 | `app/etc/env.php` | wszystkie połączenia w sekcji `db` |
| Moodle | `config.php` | `$CFG->dbhost/dbname/dbuser/dbpass`, `dboptions['dbport']` |
| Laravel | `.env` | `DB_HOST`, `DB_PORT` (dopisywany), `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Symfony | `.env`, `.env.local`, `.env.local.php` | `DATABASE_URL=mysql://...` (`serverVersion=` ustawiany na wersję nowego serwera) |
| WordPress Bedrock | `.env` | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` |
| własne skrypty PHP | dowolny `*.php` | `mysqli_connect(...)` / `new mysqli(...)` (port dopisywany jako 5. argument), `new PDO('mysql:...')`, `mysql_connect(...)`+`mysql_select_db(...)`; argumenty jako literały, zmienne lub stałe z `define()`, także z pliku dołączanego przez `require` (1 poziom) |

Pliki PHP są analizowane tokenizerem PHP (bez wykonywania kodu), podmieniane są wyłącznie konkretne literały, reszta pliku zostaje bajt w bajt. Wartości dynamiczne (np. `getenv()`) dostają uwagę i `check` nie pozwala ich migrować w trybie `migrate` – użyj `db-only` i popraw ręcznie.

## cyber_Folks: co jest inne

- Panel DirectAdmin pod `https://sXX.cyber-folks.pl:2223` (HTTPS na 2223, z SSH nie działa `127.0.0.1`).
- Natywne API baz DirectAdmin jest wyłączone („MySQL nie jest włączony na tym serwerze”); bazami zarządza wtyczka `CMD_PLUGINS/mysql`. Preset `--da-preset=cyberfolks` wysyła to samo, co jej formularze (`actions/db_create.php`, `actions/db_user_password.php`).
- Stare bazy („legacy”, MariaDB 10.6) siedzą pod `localhost` (ProxySQL). Nowa instancja ma host z portem, np. `127.0.0.1:3308`; dokładny adres i numer instancji odczytasz z formularza „Utwórz bazę danych”.
- `/usr/bin/mysqldump` i `/usr/bin/mysql` to wrappery, które nie przyjmują `--defaults-extra-file`; skrypt to wykrywa i przechodzi na hasło w `MYSQL_PWD` (ostrzeżenie przy zrzucie jest normalne).

## Inne panele

Jeśli hosting ma własną wtyczkę, podejrzyj w przeglądarce, co wysyła formularz tworzenia bazy (F12 → Network → Payload) i podaj to ręcznie:

```bash
php dbmig.php create-db --all \
  --da-url=<SERWER> \
  --da-cmd='CMD_PLUGINS/nazwa_wtyczki/sciezka' \
  --da-fields='dbname={name}&dbuser={user}&dbpass={pass}&dbpass2={pass}&instance=xyz'
```

Znaczniki: `{name}`/`{user}`/`{pass}` bez prefiksu konta, `{name_full}`/`{user_full}` z prefiksem, `{prefix}`, `{instance}`, `{comment}`, `{sites}`. Sukces jest uznawany dopiero po połączeniu się do nowej bazy danymi z `mapping.conf`.

## Pułapki, które ten skrypt już obsługuje (i które warto znać)

- **Strona pracuje w trakcie migracji.** Wpisy dodane między zrzutem a przełączeniem zostaną w starej bazie. Dla sklepów i aplikacji z użytkownikami włącz tryb konserwacji na czas `migrate` tej strony.
- **Hasła w `DATABASE_URL` (Symfony).** Znaki kodowane URL (`+` → `%2B` itd.) są przez `%env(resolve:DATABASE_URL)%` traktowane jako odwołania do parametrów kontenera („You have requested a non-existent parameter”). `plan` generuje hasła bez takich znaków; dla istniejących baz jest `repass`.
- **`.env.local.php`** (`composer dump-env prod`) ma pierwszeństwo przed `.env`; skrypt wykrywa i migruje oba.
- **opcache**: po podmianie pliku serwer WWW może przez chwilę używać starej konfiguracji (`opcache.revalidate_freq`); test HTTP czeka `--http-delay` sekund.
- **`DEFINER` i błąd 1227/1419**: zrzut ma usuwane `DEFINER=`; jeśli nowa instancja ma binlog bez `log_bin_trust_function_creators`, triggery/funkcje nie wejdą (błąd 1419) – `--no-triggers`/`--no-routines` i założenie ręczne albo zgłoszenie do hostingu.
- **Procedury cudzego właściciela** nie dadzą się zrzucić bez uprawnień; skrypt ponawia bez `--routines` i ostrzega.
- **Nazwa bazy na sztywno w SQL** (`INSERT INTO stara_baza.tabela ...`) w kodzie aplikacji – skrypt tego nie podmienia; po migracji taki kod się wywali. Szukaj: `grep -rn "<KONTO>_" --include=*.php`.
- **Strony na `mysql_*`** (PHP 5) nie ruszą po zmianie handlera PHP na 7/8 – skan to zaznacza w uwagach; to problem niezależny od bazy.
- **MariaDB ↔ MySQL**: zmiana silnika może wymagać ręcznych poprawek kolacji; import zatrzyma się przed zmianą konfiguracji.
- Nie są obsługiwane: Magento 1 (`local.xml`), konfiguracje w `.ini`/XML, hasła składane z kilku wyrażeń.

## Testy

W `tests/` jest scenariusz end-to-end na Dockerze: MySQL 5.7 jako stara instancja, MySQL 8.0 jako nowa, 16 konfiguracji różnych typów, atrapa panelu DirectAdmin i wtyczki cyber_Folks (`tests/da-mock`), symulowany wrapper `mysqldump`, testy rollbacku, auto-rollbacku i `repass`:

```bash
cd tests && docker compose up --build --abort-on-container-exit --exit-code-from php
```

## English quick start

Run on the hosting server over SSH (DirectAdmin layout `~/domains/<domain>/public_html`):

```bash
mkdir -p ~/dbmig && cd ~/dbmig
curl -LO https://github.com/nieprzecietny/dbmig/releases/latest/download/dbmig.php
php dbmig.php scan                                   # inventory of all sites + their DB credentials -> dbmig-work/mapping.conf
php dbmig.php plan --all --new-host=127.0.0.1:3308   # generate names/users/passwords of the new databases
php dbmig.php create-db --all --da-preset=cyberfolks --da-instance=2 --da-url=https://sXX.cyber-folks.pl:2223
php dbmig.php check                                  # connections, empty new DBs, files, HTTP baseline - no changes
php dbmig.php migrate --site example.pl --dry-run
php dbmig.php migrate --site example.pl              # dump -> import -> verify -> rewrite config -> HTTP probe (auto-rollback)
php dbmig.php migrate --all
php dbmig.php rollback --site example.pl             # back to the old DB (left untouched) if anything is wrong
```

Messages are in Polish; the command reference above and `php dbmig.php --help` list every option. Exit code 0 = success, 1 = error (nothing further was touched), 2 = usage error.

## Dla agentów AI i automatyzacji

Skrypt da się prowadzić bez terminala interaktywnego. Zasady:

- Potwierdzenia pomija `--yes`; kolory wyłącza `--no-color`; hasło do panelu podaj przez zmienną `DA_PASS` (nigdy w linii poleceń), adres panelu przez `DA_URL`, preset przez `DA_PRESET`, instancję przez `DA_INSTANCE`, katalog roboczy przez `DBMIG_WORKDIR`.
- Każda komenda zwraca kod wyjścia: `0` sukces, `1` błąd (skrypt zatrzymał się przed kolejną zmianą; stan w `dbmig-work/state.json`), `2` zły sposób użycia.
- Przed zmianą czegokolwiek uruchom `check` i `migrate --dry-run`; niezerowy kod = nie kontynuuj.
- Plik `dbmig-work/mapping.conf` jest jedynym źródłem decyzji: `action = migrate` włącza wiersz. Edytuj go zamiast obchodzić skrypt.
- Nigdy nie usuwaj starych baz skryptem – nie ma na to komendy i to celowe. Kasowanie legacy robi człowiek w panelu po testach.
- Log: `dbmig-work/logs/dbmig.log` (bez haseł). `inventory.csv` i `mapping.conf` zawierają hasła, trzymaj je poza `public_html`.

Minimalny przepis nieinteraktywny:

```bash
export DBMIG_WORKDIR=$HOME/dbmig/dbmig-work DA_URL=https://sXX.cyber-folks.pl:2223 DA_PRESET=cyberfolks DA_INSTANCE=2 DA_PASS='...'
php dbmig.php scan --no-color --no-db-test
php dbmig.php plan --all --new-host=127.0.0.1:3308 --no-color
# (edycja mapping.conf: action = skip dla kopii/martwych stron)
php dbmig.php create-db --all --yes --no-color     || exit 1
php dbmig.php check --no-color                     || exit 1
php dbmig.php migrate --all --dry-run --no-color   || exit 1
php dbmig.php migrate --all --yes --no-color       || exit 1
php dbmig.php status --no-color
```

Streszczenie dla modeli językowych jest też w [`llms.txt`](llms.txt), a wskazówki dla agentów pracujących nad kodem w [`AGENTS.md`](AGENTS.md).

## Słowa kluczowe

migracja bazy danych na nową instancję MySQL, cyber_Folks DB Legacy, MariaDB 10.6 → 10.11, DirectAdmin „Zarządzanie MySQL”, hosting współdzielony, masowa zmiana `wp-config.php`, przeniesienie wielu baz WordPress/PrestaShop/Joomla/Symfony/Laravel jednym skryptem, `mysqldump` bez SUPER (`DEFINER`), zmiana hosta bazy `localhost` → `127.0.0.1:3308`, automatyczny rollback konfiguracji.

shared hosting MySQL instance migration, DirectAdmin bulk database migration, move all sites to a new MariaDB instance, WordPress wp-config.php mass update, mysqldump without SUPER privilege, cyber_Folks legacy database migration tool, PHP CLI.

## Podziękowanie

Jeśli ten skrypt zaoszczędził Ci parę godzin klikania w panelu, możesz podziękować, zostając sponsorem: **[github.com/sponsors/nieprzecietny](https://github.com/sponsors/nieprzecietny)** (przycisk „Sponsor” u góry repozytorium). Zgłoszenia błędów i pull requesty z obsługą kolejnych systemów też są mile widziane.

*If it saved you a few hours of clicking, consider [sponsoring](https://github.com/sponsors/nieprzecietny).*

## Licencja

MIT – patrz `LICENSE`. Skrypt zmienia pliki produkcyjne i bazy na Twoim koncie; używasz go na własną odpowiedzialność, zawsze najpierw z `--dry-run`.
