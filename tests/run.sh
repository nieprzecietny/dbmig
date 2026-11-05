#!/bin/bash
# Scenariusz testowy dbmig.php (uruchamiany w kontenerze php, patrz docker-compose.yml)
set -u
export DBMIG_WORKDIR=/work
D="php /app/dbmig.php"
FAIL=0
step() { echo; echo "################################################################"; echo "## $*"; echo "################################################################"; }
expect_ok()   { if [ "$1" -eq 0 ]; then echo ">> OK (exit $1)"; else echo ">> FAILED (exit $1) - expected success"; FAIL=$((FAIL+1)); fi; }
expect_fail() { if [ "$1" -ne 0 ]; then echo ">> OK (exit $1, expected failure)"; else echo ">> FAILED (exit 0) - expected failure"; FAIL=$((FAIL+1)); fi; }

cd /
rm -rf /sites /work; mkdir -p /work
cp -r /fixtures /sites
ln -sfn laravel/public /sites/laravel.pl/public_html
chmod -R u+w /sites
cd /work
MYSQL="mysql --skip-ssl"

# symulacja wrappera hostingu: opcja PRZED naszymi -> --defaults-extra-file przestaje byc pierwszy
printf '#!/bin/sh\nexec /usr/bin/mysqldump --skip-ssl-verify-server-cert "$@"\n' > /usr/local/bin/mysqldump && chmod +x /usr/local/bin/mysqldump
php -v | head -1
php -m | grep -i mysqli
mysql --version; mysqldump --version

(cd /sites/example.pl/public_html && php -S 127.0.0.1:8081 >/tmp/srv1.log 2>&1 &)
(cd /sites/broken.pl/public_html && php -S 127.0.0.1:8082 >/tmp/srv2.log 2>&1 &)
(cd /tests/da-mock && php -S 127.0.0.1:2222 /tests/da-mock/index.php >/tmp/da-srv.log 2>&1 &)
sleep 1
echo "srv1: $(curl -s http://127.0.0.1:8081/)"
echo "srv2: $(curl -s http://127.0.0.1:8082/)"

step "php -l"
php -l /app/dbmig.php; expect_ok $?

step "help"
$D --help | head -5; expect_ok ${PIPESTATUS[0]}

step "scan"
$D scan --root=/sites --no-color; expect_ok $?

step "inventory.csv"
cat /work/inventory.csv

step "plan --all (generowanie new_*)"
$D plan --all --suffix=8 --da-user=acc --no-color; expect_ok $?
$D plan --all --new-host=mysql8 --da-user=acc --no-color | tail -3; expect_ok ${PIPESTATUS[0]}
awk -f /tests/patch-mapping.awk /work/mapping.conf > /work/m2 && mv /work/m2 /work/mapping.conf
grep -v '^#' /work/mapping.conf | head -60

step "create-db --list natywne API (atrapa: wylaczone jak na cyber_Folks)"
$D create-db --list --da-url=http://127.0.0.1:2222 --da-user=acc --da-pass=panelpass --no-color; expect_fail $?
step "create-db --list --da-preset=cyberfolks (lista z wtyczki, jeszcze pusta)"
$D create-db --list --da-preset=cyberfolks --da-url=http://127.0.0.1:2222 --da-user=acc --da-pass=panelpass --no-color; expect_fail $?

step "create-db --all --dry-run"
$D create-db --all --dry-run --da-url=http://127.0.0.1:2222 --da-user=acc --da-pass=panelpass --no-color; expect_ok $?

step "create-db --all natywne API (atrapa: wylaczone -> blad)"
$D create-db --all --yes --da-url=http://127.0.0.1:2222 --da-user=acc --da-pass=panelpass --no-color; expect_fail $?

step "create-db --all preset cyberfolks, zla instancja (wtyczka zglasza blad w HTML)"
$D create-db --all --yes --da-preset=cyberfolks --da-instance=1 --da-url=http://127.0.0.1:2222 --da-user=acc --da-pass=panelpass --no-color; expect_fail $?

step "create-db --all preset cyberfolks, instancja 2"
DA_PASS=panelpass $D create-db --all --yes --da-preset=cyberfolks --da-instance=2 --da-url=http://127.0.0.1:2222 --da-user=acc --no-color; expect_ok $?
$D create-db --list --da-preset=cyberfolks --da-url=http://127.0.0.1:2222 --da-user=acc --da-pass=panelpass --no-color; expect_ok $?
echo "--- da-mock.log:"; sed -E 's/"passwd2?":"[^"]*"/"passwd":"***"/g' /tmp/da-mock.log | tail -5

step "check"
$D check --no-color; expect_ok $?

step "show example.pl"
$D show --site example.pl --no-color; expect_ok $?

step "migrate --all --dry-run"
$D migrate --all --dry-run --no-color; expect_ok $?

step "migrate --site example.pl"
$D migrate --site example.pl --yes --no-color; expect_ok $?
echo "srv1 after: $(curl -s http://127.0.0.1:8081/)"
echo "--- wp-config.php after:"; grep -n "DB_" /sites/example.pl/public_html/wp-config.php

step "migrate --all (reszta)"
$D migrate --all --yes --no-color; expect_ok $?

step "status"
$D status --no-color; expect_ok $?

step "configs after"
for f in /sites/joomla.pl/public_html/configuration.php /sites/presta.pl/public_html/app/config/parameters.php \
         /sites/presta16.pl/public_html/config/settings.inc.php /sites/opencart.pl/public_html/config.php /sites/opencart.pl/public_html/admin/config.php \
         /sites/magento.pl/public_html/app/etc/env.php /sites/drupal.pl/public_html/sites/default/settings.php /sites/moodle.pl/public_html/config.php \
         /sites/laravel.pl/laravel/.env /sites/symfony.pl/public_html/.env /sites/symfony.pl/public_html/.env.local.php /sites/custom.pl/public_html/config.php /sites/pdo.pl/public_html/index.php \
         /sites/legacy.pl/public_html/connect.php; do
  echo "--- $f"; diff -u /fixtures${f#/sites} "$f" | grep -E '^[-+][^-+]' | sed -E "s/(pass|PASSW|password|dbpass|_PASSWD_)([^,;)]*)/\1=***/I" ; done

step "nowe bazy: tabele / trigger / view / dane"
$MYSQL -h mysql8 -uroot -proot -e "SELECT table_schema, COUNT(*) FROM information_schema.tables WHERE table_schema LIKE 'acc_%' GROUP BY 1; SHOW TRIGGERS FROM acc_wp8\G; SHOW CREATE VIEW acc_wp8.wp_view\G; SELECT * FROM acc_wp8.wp_options; SELECT * FROM acc_wp8.wp_posts; SELECT * FROM acc_wp8.wp_log; SELECT HEX(bin_value) FROM acc_wp8.wp_options WHERE bin_value IS NOT NULL; SELECT ROUTINE_SCHEMA, ROUTINE_NAME, ROUTINE_TYPE, DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA LIKE 'acc_%'; SELECT acc_wp8.wp_double(21);" 2>&1 | grep -v "Using a password"
echo "--- stara baza nietknieta:"
$MYSQL -h mysql57 -uroot -proot -e "SELECT COUNT(*) FROM acc_wp.wp_posts; SHOW TRIGGERS FROM acc_wp\G" 2>&1 | grep -v "Using a password" | head -8

step "repass --site symfony.pl (zmiana hasla acc_app8 przez wtyczke + podmiana we wszystkich plikach tej bazy)"
$D repass --site symfony.pl --all-passwords --yes --da-preset=cyberfolks --da-instance=2 --da-url=http://127.0.0.1:2222 --da-user=acc --da-pass=panelpass --no-color; expect_ok $?
grep -h "DATABASE_URL" /sites/symfony.pl/public_html/.env /sites/symfony.pl/public_html/.env.local.php | sed -E 's#://[^:]*:([^@]*)@#://user:\1@#' | grep -E "^.{0,20}" | sed -E 's/(:)[^@]*(@)/\1<haslo>\2/'
NEWPASS=$(grep -A14 '^\[custom.pl' /work/mapping.conf | grep '^new_pass' | sed 's/^new_pass = //')
$MYSQL -h mysql8 -uacc_app8 -p"$NEWPASS" -e "SELECT 'nowe haslo dziala' AS ok" 2>&1 | grep -v "Using a password"
$D check --no-color 2>&1 | tail -2; expect_ok ${PIPESTATUS[0]}

step "migrate --all drugi raz (idempotencja: nic do zrobienia)"
$D migrate --all --yes --no-color; expect_fail $?

step "rescan po migracji (workdir pomijany, wiersze aktualne)"
$D scan --root=/sites --no-color --no-db-test | grep -E "dbmig-work|Znaleziono|backups" ; expect_ok ${PIPESTATUS[0]}

step "rollback example.pl"
$D rollback --site example.pl --yes --no-color; expect_ok $?
diff /fixtures/example.pl/public_html/wp-config.php /sites/example.pl/public_html/wp-config.php && echo ">> wp-config.php identyczny z oryginalem" || { echo ">> FAILED: wp-config.php rozni sie po rollbacku"; FAIL=$((FAIL+1)); }
echo "srv1 after rollback: $(curl -s http://127.0.0.1:8081/)"

step "clean-new + migrate --redo (example.pl)"
$D clean-new --site example.pl --yes --no-color; expect_ok $?
$D migrate --site example.pl --redo --yes --no-color; expect_ok $?
echo "srv1 after redo: $(curl -s http://127.0.0.1:8081/)"

step "auto-rollback: broken.pl (config-only, strona po zmianie zglasza blad bazy)"
awk '/^\[broken\.pl/ {b=1} /^\[/ && !/^\[broken\.pl/ {b=0} b && /^action =/ { $0 = "action = config-only" } { print }' /work/mapping.conf > /work/m2 && mv /work/m2 /work/mapping.conf
$D update-config --site broken.pl --yes --no-color; expect_fail $?
diff /fixtures/broken.pl/public_html/wp-config.php /sites/broken.pl/public_html/wp-config.php && echo ">> broken.pl wp-config.php przywrocony (auto-rollback OK)" || { echo ">> FAILED: broken.pl wp-config.php nie zostal przywrocony"; FAIL=$((FAIL+1)); }
echo "srv2 after: $(curl -s http://127.0.0.1:8082/)"

step "update-config --dry-run broken.pl"
$D update-config --site broken.pl --dry-run --no-color; expect_ok $?

step "status koncowy"
$D status --no-color

step "stan plikow roboczych"
ls -la /work /work/dumps /work/backups; tail -5 /work/logs/dbmig.log

echo
echo "=================  WYNIK: bledow testu: $FAIL  ================="
exit $FAIL
