# Záloha a obnova docházky

Záloha databáze centrály obsahuje události, žádosti, fotografie propustek, schválení, všechny uložené verze mzdových CSV, účty a audit. `pg_dump` pořídí konzistentní kopii běžící databáze. Provozovatel stanoví interval podle toho, kolik posledních záznamů může při výpadku ztratit, a určí dobu uchování i termín zkušební obnovy. [Dokumentace PostgreSQL k zálohování](https://www.postgresql.org/docs/17/app-pgdump.html)

## Pořízení zálohy

Příkazy spusťte v Bashi z adresáře s nasazeným `compose.yaml` a jeho `.env`. Nastavte `PROJECT_NAME` na název skutečného Compose projektu a `BACKUP_DIR` na soukromý adresář na šifrovaném záložním úložišti. Úložiště musí být dostupné i při ztrátě hostitele centrály.

~~~bash
set -euo pipefail
umask 077
: "${PROJECT_NAME:?Nastavte název nasazeného Compose projektu}"
: "${BACKUP_DIR:?Nastavte adresář pro šifrované zálohy}"
backup_set=$(mktemp -d "$BACKUP_DIR/dochazka-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
docker compose -p "$PROJECT_NAME" -f compose.yaml exec -T db \
    pg_dump -U dochazka -Fc dochazka > "$backup_set/database.partial"
mv "$backup_set/database.partial" "$backup_set/database.dump"
(cd "$backup_set" && sha256sum database.dump > SHA256SUMS)
~~~

U sady poznamenejte verzi vydání centrály. Konfiguraci `.env`, přístupové údaje pro obnovu, GPO a případný Kerberos keytab spravujte odděleně v chráněném úložišti. Záznamy, které Windows agent dosud neodeslal, jsou ještě v jeho lokální frontě. Zálohy a jejich rozšifrování zpřístupněte jen osobám pověřeným obnovou. To platí i pro lokální testovací instance, které obsahují uživatelské účty nebo skutečné přílohy. Dump ani fotografie nepatří do repozitáře, issue, CI artefaktů nebo jiného veřejného úložiště.

## Zkušební obnova

Vyberte důvěryhodnou sadu a nastavte `backup_set` na její cestu. Příkazy vytvoří samostatný Compose projekt s novým databázovým svazkem a spustí jen databázi. Přihlašovací údaje cílové instance jsou dočasné. Obnova proběhne v jedné transakci. [Dokumentace PostgreSQL k obnově](https://www.postgresql.org/docs/17/app-pgrestore.html)

~~~bash
set -euo pipefail
: "${backup_set:?Nastavte cestu k sadě zálohy}"
(cd "$backup_set" && sha256sum -c SHA256SUMS)
restore_project="dochazka-recovery-$(openssl rand -hex 8)"
restore_password=$(openssl rand -hex 32)
restore_compose() {
    DB_PASSWORD="$restore_password" docker compose -p "$restore_project" -f compose.yaml "$@"
}
restore_compose up -d --wait db
restore_compose exec -T db pg_restore -U dochazka -d dochazka \
    --single-transaction --exit-on-error --no-owner --no-acl < "$backup_set/database.dump"
restore_compose exec -T db psql -X -v ON_ERROR_STOP=1 -U dochazka -d dochazka <<'SQL'
SELECT count(*) AS pocet_propustek FROM medical_proof;
SELECT month, version, encode(sha256(convert_to(content, 'UTF8')), 'hex') AS sha256_csv
FROM export ORDER BY month DESC, version DESC LIMIT 10;
SQL
~~~

Porovnejte poslední očekávanou uzávěrku a její kontrolní součet s dříve převzatým CSV. Při pravidelné zkoušce ověřte také propustku pro vybranou schválenou žádost. Datum výsledku a dobu potřebnou k obnově zapište do provozního záznamu. Automatická zkouška v `tests/deploy.sh` porovnává po obnově každou verzi testovacího exportu i obsah a přiřazení fotografie.

Po dokončení zkoušky lze dočasná data odstranit příkazem `restore_compose down --volumes` v témže shellu. Při skutečném výpadku zachovejte ověřený svazek, nasaďte odpovídající verzi aplikace a původní doménu pro passkeys. Před obnovením příjmu požadavků zkontrolujte změny účtů a revokace zařízení od okamžiku zálohy a znovu uplatněte potřebná odebrání přístupu. Přesměrování Traefiku proveďte po ověření výkazů a oprávnění.
