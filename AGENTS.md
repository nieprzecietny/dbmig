# AGENTS.md – wskazówki dla agentów AI pracujących nad tym repozytorium

## Co to jest

`dbmig.php` to jednoplikowe narzędzie PHP CLI do masowej migracji baz MySQL/MariaDB stron na hostingu współdzielonym (układ DirectAdmin) na nową instancję. Dokumentacja użytkownika: `README.md` (po polsku, z angielskim quick startem). Streszczenie maszynowe: `llms.txt`.

## Układ repozytorium

- `dbmig.php` – cały program, jeden plik, bez zależności Composera. Sekcje w kolejności: CLI/log/workdir/state → tokenizer PHP (`PhpFile`, `php_find_*`) → parsery konfiguracji (`parse_*`, sloty) → skaner (`Scanner`) → warstwa DB i mysqldump/import (`Db`, `MyAuth`, `do_dump`, `do_import`, `do_verify`) → podmiana plików (`build_edits`, `backup_files`, `http_probe`) → `mapping.conf` i wiersze (`load_rows`, `row_prepare`, `build_jobs`) → komendy (`cmd_*`) → `plan`/`create-db`/`repass` (API panelu) → `usage()`/`main()`.
- `tests/` – test end-to-end na Dockerze: `docker-compose.yml` (MySQL 5.7 „stara”, MySQL 8.0 „nowa”, kontener PHP), `run.sh` (scenariusz, kroki `>> OK`/`>> FAILED`), `sites/` (16 konfiguracji różnych systemów), `da-mock/index.php` (atrapa panelu DirectAdmin i wtyczki cyber_Folks, naprawdę zakłada bazy na mysql8), `sql/` (dane startowe), `patch-mapping.awk`.

## Jak testować

```bash
cd tests && docker compose up --build --abort-on-container-exit --exit-code-from php
```

Wynik kończy się linią `WYNIK: bledow testu: N`. Oczekiwane `0`. Przed commitem dodatkowo: `php -l dbmig.php` na PHP 7.2 i 8.x (np. `docker run --rm -v "$PWD:/app:ro" php:7.2-cli php -l /app/dbmig.php`) oraz `php dbmig.php --help` (tekst pomocy przechodzi przez `sprintf` – znak `%` zapisuj jako `%%`, a `$` w heredocu escapuj).

## Zasady, których nie wolno naruszyć

1. Stara baza jest tylko czytana. Żadna komenda nie może wykonywać `DROP`, `ALTER`, `DELETE` ani zmiany hasła na starej bazie. `clean-new` działa wyłącznie na nowej bazie i odmawia, gdy klucz bazy jest taki sam jak starej.
2. Każda zmiana pliku konfiguracyjnego przechodzi przez `backup_files` → `apply_edits_to_content` → `write_file_atomic` → `php -l` → ponowne parsowanie i porównanie z oczekiwanymi polami → test HTTP → przy błędzie `restore_backup`. Nowe typy konfiguracji muszą używać tej ścieżki (`row_update_config`).
3. Pliki PHP są analizowane wyłącznie tokenizerem (`token_get_all`), nigdy przez `include`/`eval`. Podmieniane są tylko literały (`T_CONSTANT_ENCAPSED_STRING`, `T_LNUMBER`); wartości dynamiczne dostają `dyn = true` i blokują `migrate`.
4. Hasła nie trafiają na linię poleceń innych procesów ani do logu: klienty mysql dostają `--defaults-extra-file` (plik 0600) albo `MYSQL_PWD`; wyjście maskuje hasła przez `mask()`; `Log::append` w `main()` ukrywa argumenty zawierające `pass`.
5. Wszystko, co zmienia stan, respektuje `--dry-run` i `--yes`, zwraca kod `1` przy błędzie i zatrzymuje się na pierwszym błędzie bez `--continue-on-error`.
6. Kompatybilność z PHP 7.2: bez typowanych właściwości, `match`, `str_contains`, funkcji strzałkowych, named arguments.
7. Komunikaty dla użytkownika są po polsku, bez ogonków w kodzie (ASCII) – tak jak w istniejącym tekście.
8. W repozytorium nie mogą pojawić się dane identyfikujące konkretne konto hostingowe, serwer, domeny klientów ani prawdziwe hasła. Przykłady: `konto_`, `sXX.cyber-folks.pl`, `example.pl`.

## Jak dodać obsługę nowego systemu CMS

1. Napisz `parse_<cms>($path)` zwracające `parse_result(...)` ze slotami (`slot_php` dla literałów PHP, `slot_env` dla plików liniowych). Typy slotów: `host`, `hostport`, `port`, `name`, `user`, `pass`, `dsn`, `url`.
2. Dodaj wykrywanie pliku w `Scanner::walk` i przypadek w `parse_by_cms`.
3. Dodaj fixture w `tests/sites/<nazwa>.pl/...` wskazujące na jedną z baz testowych (`acc_wp`, `acc_shop`, `acc_app`) i wpis w liście `configs after` w `tests/run.sh`.
4. Uruchom test e2e.

## Wydania

Wersja jest w stałej `DBMIG_VERSION` w `dbmig.php`; tag git `vX.Y.Z`; release na GitHubie ma załączony `dbmig.php` (link `releases/latest/download/dbmig.php` w README musi działać).
