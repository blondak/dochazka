# Automatická registrace stanic v Samba AD

Spouštěcí GPO v kontextu počítače spustí instalátor jako LocalSystem. Windows předá interní registrační bráně přes Kerberos identitu stanice. Brána ověří přes LDAPS, že jde o počítač ve zvolené doméně a přímého člena povolené skupiny. Centrála vydá token jen této stanici. Při dalších startech instalátor použije uloženou konfiguraci. [Účty počítačů a LocalSystem](https://learn.microsoft.com/en-us/entra/architecture/service-accounts-computer)

## Brána na řadiči Debianu a centrála na internetu

Na řadiči Samba AD spustí instalační skript samostatnou službu `dochazka-gateway` pod vyhrazeným uživatelem. Apache poskytuje HTTPS přímo na zadané interní adrese a obsluhuje registrace pouze ze zadaných sítí. Brána využívá lokální LDAPS a odchozí HTTPS na TCP 443 k centrále. Centrála ověřuje podpis, příslušnost k nastavenému realmu, stáří požadavku a jednorázový identifikátor. Windows stanice při instalaci navazují spojení s bránou a při běžném provozu odesílají docházku přímo centrále.

### Připravte centrálu

1. Připravte soubor se sdíleným klíčem v chráněném adresáři mimo Git. Vygenerujte jej jednou příkazem `umask 077; openssl rand -hex 32 > gateway-enroll.key` a bezpečně přeneste stejný soubor na řadič. Další instalace a aktualizace používají tentýž klíč.
2. Aktualizujte obrazy `app` a `web` alespoň na verzi `0.0.2`, která přidává endpoint `/api/v1/gateway-enroll`.
3. Přidejte [Compose konfiguraci centrály pro bránu](compose.gateway-access.yaml) za stávající Compose soubory. Nastavte `GATEWAY_ENROLL_REALM` na hodnotu z `sudo testparm -s --parameter-name realm` na řadiči. Do `GATEWAY_ENROLL_KEY_SOURCE` zadejte absolutní cestu ke klíči na hostiteli centrály. PHP-FPM musí soubor číst. Při běžném Dockeru na Linuxu použijte pro soubor UID 33 a režim `0400`.
4. Pomocí stejné sady Compose souborů spusťte `docker compose … up -d app web`. Centrála při startu `app` provede migraci. Stávající Traefik a veřejná adresa obslouží nový endpoint stejně jako ostatní API požadavky.

### Spusťte instalaci na řadiči

Připravte interní DNS jméno, pevnou IPv4 adresu řadiče, síť stanic a PEM certifikát s privátním klíčem pro toto DNS jméno. Certifikační autoritě musí důvěřovat účty počítačů Windows, například přes politiku důvěryhodných kořenových certifikátů. Pro LDAPS použije skript CA z nastavení Samba. Pokud certifikát řadiče vyžaduje jiné DNS jméno nebo CA, použijte `--ldap-host` nebo `--ldap-ca`.

Adresy a cesty v ukázce nahraďte svými hodnotami.

~~~sh
sudo /usr/bin/python3 deploy/gpo/debian/install.py \
    --host dochazka-gw.example.cz \
    --listen 192.168.10.10 \
    --allow 192.168.10.0/24 \
    --central https://dochazka.example.cz \
    --key-file /root/dochazka/gateway-enroll.key \
    --tls-cert /root/dochazka/gateway-fullchain.pem \
    --tls-key /root/dochazka/gateway.key \
    --manage-dns \
    --pilot PILOT01
~~~

Skript nainstaluje balíčky Debianu, zjistí realm, založí účty `dochazka-http` a `dochazka-ldap`, zaregistruje `HTTP/<jméno-brány>` a uloží keytab pro tento SPN. Vytvoří nebo použije bezpečnostní skupinu `Dochazka-PC`. `--pilot` je volitelný a přidává existující účty počítačů do této skupiny. S `--manage-dns` skript v AD zóně doplní chybějící A záznam. Pro vlastní správu DNS tuto volbu vynechte a připravte záznam ručně. Služba používá TCP 443. Pokud je obsazený, použijte jiný port přes `--port` a předejte stejný port i v `-EnrollmentServer`. [Příručka samba-tool](https://www.samba.org/samba/docs/current/man-html/samba-tool.8.html) · [mod_auth_gssapi pro Apache](https://github.com/gssapi/mod_auth_gssapi)

Při obnově certifikátu spusťte znovu tentýž příkaz s novými soubory. Skript použije existující služební účty. Stav služby ověřte přes `systemctl status dochazka-gateway`, chyby Apache jsou v `/var/log/dochazka-gateway/error.log`. Úspěšná instalace vypíše adresu pro `-EnrollmentServer`. Nejprve nasaďte GPO na pilotní stanici podle postupu níže a ověřte její registraci v centrále.

## Brána ve stejném projektu Docker Compose jako centrála

1. Zvolte vnitřní DNS jméno brány, například `enroll.example.cz`. V existujícím Traefiku pro něj směrujte HTTPS na `http://dochazka-enroll:80` pouze z firemní sítě nebo VPN. Zachovejte hlavičku `Authorization` a odpověď `WWW-Authenticate`. Pro běžný provoz agenta zůstává adresa centrály `https://dochazka.example.cz`. FastCGI port 9000 je dostupný pouze uvnitř projektu.
2. Na řadiči založte služební účet pro SPN brány, samostatný účet pro čtení LDAP a skupinu počítačů. Přihlašovací údaje k LDAP ani keytab se nedistribuují stanicím. Následující příklad používá existující doménu a existující počítač `PILOT01`. Jména a DN přizpůsobte vlastním organizačním jednotkám. [Příručka samba-tool](https://www.samba.org/samba/docs/current/man-html/samba-tool.8.html)

~~~sh
umask 077
samba-tool user create dochazka-http --random-password
samba-tool spn add HTTP/enroll.example.cz dochazka-http
samba-tool domain exportkeytab /bezpecna/cesta/http.keytab --principal=HTTP/enroll.example.cz
samba-tool user create dochazka-ldap
samba-tool group add Dochazka-PC
samba-tool group addmembers Dochazka-PC 'PILOT01$'
~~~

3. Na Docker host přeneste keytab obsahující pouze uvedený HTTP principal, důvěryhodnou CA pro LDAPS a jednořádkový soubor s heslem LDAP účtu. Pracovní proces Apache v obrazu běží jako `www-data` (UID 33) a musí mít přístup ke keytabu. Keytab může vlastnit UID 33 s režimem 0400. Heslo ponechte vlastníkovi root s režimem 0400. Název LDAP serveru musí odpovídat jeho certifikátu. Ověřte dostupnost DNS a časovou synchronizaci stanic, brány a DC.
4. Připravte klientskou konfiguraci MIT Kerberos se správnou `default_realm` a KDC, například:

~~~ini
[libdefaults]
    default_realm = EXAMPLE.CZ
    rdns = false
[realms]
    EXAMPLE.CZ = {
        kdc = dc.example.cz
    }
~~~

5. Doplňte k proměnným centrály do `.env` následující hodnoty a spusťte bránu. Adresy jsou příklady. [Ověření Kerberos v Apache](https://github.com/gssapi/mod_auth_gssapi) · [Autorizace skupin v LDAP](https://httpd.apache.org/docs/2.4/mod/mod_authnz_ldap.html)

~~~dotenv
GPO_IMAGE=ghcr.io/blondak/dochazka:enroll-0.0.6
GPO_REALM=EXAMPLE.CZ
GPO_LDAP_HOST=dc.example.cz
GPO_BASE_DN=DC=example,DC=cz
GPO_BIND_DN=CN=dochazka-ldap,CN=Users,DC=example,DC=cz
GPO_GROUP_DN=CN=Dochazka-PC,CN=Users,DC=example,DC=cz
GPO_KRB5_CONF=/etc/dochazka/krb5.conf
GPO_KEYTAB=/etc/dochazka/http.keytab
GPO_BIND_PASSWORD_FILE=/etc/dochazka/ldap-password
GPO_LDAP_CA=/etc/dochazka/ldap-ca.pem
~~~

~~~sh
docker compose -f compose.yaml -f deploy/compose.traefik.yaml -f deploy/compose.gpo.yaml pull enroll
docker compose -f compose.yaml -f deploy/compose.traefik.yaml -f deploy/compose.gpo.yaml up -d --no-deps --no-build enroll
~~~

Pro vlastní sestavení ze zdrojového kódu použijte ve stejném projektu `docker compose -f compose.yaml -f deploy/compose.traefik.yaml -f deploy/compose.gpo.yaml up -d --no-deps --build enroll`.

## Nasaďte GPO na pilotní stanici

1. Rozbalte vybranou [variantu Windows balíčku](../README.md#windows-agent-v-doméně-samba-ad) na sdílení čitelné účty počítačů. Zápis vyhraďte správcům distribuce. V adresáři centrály přiřaďte Windows SID zaměstnance, který bude pilotní stanici používat. Svůj SID zjistí pomocí `whoami /user`.
2. V GPO v **Computer Configuration → Windows Settings → Scripts → Startup** nastavte spouštěcí PowerShell skript níže. Pro počítače, jež dostávají doménovou síť až po startu, nastavte ve stejné politice čekání na síť při startu a přihlášení. Při opakovaném běhu po dokončené registraci se použije existující token.

~~~powershell
$ErrorActionPreference = 'Stop'

$package = '\\server\dochazka\agent-v0.0.6-wts'
$logDirectory = Join-Path $env:ProgramFiles 'DochazkaAgent\Logs'
$log = Join-Path $logDirectory 'startup.log'
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
Add-Content -LiteralPath $log -Encoding UTF8 -Value "$(Get-Date -Format o) START $(whoami)"

$installParams = @{
    Server           = 'https://dochazka.example.cz'
    EnrollmentServer = 'https://enroll.example.cz'
    PackagePath      = $package
    Release          = '0.0.6-wts'
}

try {
    & (Join-Path $package 'Install-Agent.ps1') @installParams
    Add-Content -LiteralPath $log -Encoding UTF8 -Value "$(Get-Date -Format o) OK"
} catch {
    Add-Content -LiteralPath $log -Encoding UTF8 -Value "$(Get-Date -Format o) ERROR $($_.Exception.Message)"
    throw
}
~~~

Průběh instalace najdete v souboru `%ProgramFiles%\DochazkaAgent\Logs\startup.log`.

3. Povolte úspěšné audity **Logon** i **Logoff** v Advanced Audit Policy. Restartujte pilotní stanici, ověřte službu `DochazkaAgent` a položku `PILOT01$@EXAMPLE.CZ` na kartě **Zařízení**. Na pilotní stanici vyzkoušejte místní přihlášení a odhlášení běžného účtu i správce. Výkaz má zobrazit jednu uzavřenou relaci. Ověřte také **Spustit jako správce** s potvrzením i se zadáním účtu správce a **Spustit jako jiný uživatel**. Původní relace má zůstat otevřená až do odhlášení z Windows.
4. Pokud chybí vazba na zaměstnance, centrála na kartě **Zařízení** zobrazí upozornění s nepřiřazeným SID. Doplňte jej v adresáři. Agent doručení zopakuje každou hodinu a pokouší se o přiřazení po dobu 30 dnů od prvního odmítnutí. Chyby sběru a spojení najdete také v aplikačním protokolu Windows pod zdrojem `DochazkaAgent`.

Služba dostává oznámení o přihlášení a odhlášení místní relace přímo od Windows. Zámek obrazovky, přepnutí uživatele ani odpojení vzdálené plochy relaci neukončuje. Agent uloží události do SQLite a pokusí se je hned odeslat. Pokud stanice nemá spojení, odešle je dodatečně. Přihlášení `4624` a odhlášení `4634` z protokolu `Security` slouží k dočtení. Přímé oznámení i auditní záznam používají stejný klíč relace, včetně spojených tokenů správce. Protokol `System` slouží k rozlišení startů systému a čtení událostí spánku a probuzení `42`/`107`.

Čtení protokolů a opakování doručení běží zhruba po 30 sekundách. Bez událostí a hlášení se agent centrále ozve při startu a pak zhruba jednou za pět minut.

Při řízeném vypnutí Windows se služba pokusí načíst poslední události včetně odhlášení a odeslat čekající dávky. Na ukončení služby je vyhrazeno nejvýše pět sekund a jeden požadavek má limit dvě sekundy. Nepotvrzené dávky zůstanou ve frontě. Události zapsané později načte agent při dalším startu z protokolu Windows.

## Servisní přihlášení

Použijte centrálu `docker-v0.0.5` nebo novější a aktualizujte agenta na balíček se systémovým dialogem. Při aktualizaci změňte parametr `-Release`. Instalátor nastaví spuštění ikony **Docházka** při interaktivním přihlášení. Pokud je ikona skrytá, otevřete skryté ikony v oznamovací oblasti Windows.

Po samostatném místním přihlášení pod účtem zaměstnance zvolte **Označit relaci jako servisní**. Ikona zobrazí systémový dialog s názvem stanice a časem začátku relace. Zadejte vlastní účet správce centrály. Heslo se použije jen k potvrzení této relace a agent je neuloží. Dialog komunikuje s centrálou přes HTTPS z uživatelské relace Windows a používá její nastavení proxy. Pro ověření pomocí passkey zvolte **Označit pomocí passkey v prohlížeči**. Pokud agent ještě přihlášení neodeslal, zopakujte akci po další dávce. Zpětně upravíte příznak jako správce ve **Výkazu** na záložce **Relace počítače**.

Servisní relace zůstává uložená a v běžném výkazu se zobrazí po zapnutí **Zobrazit servisní relace**. Evidované servisní úseky se vynechávají ze součtu odpracované doby a z mzdového exportu.

Vedoucí v **Denním přehledu** vidí zaměstnance svého týmu a stav podle dnešní evidence Windows. Délka práce se zobrazí po zaznamenaném odhlášení. Pokud se zaměstnanec znovu přihlásí, přehled ukáže stav **Přítomen** a součet už uzavřených relací. Servisní relace se v přehledu nezapočítávají.

## Obnova a vyřazení

Pokud pilotní stanice potřebují proxy, přidejte do startup skriptu `-ProxyUri 'http://proxy.example.cz:3128'`. Režim se standardně použije pro registraci i odesílání událostí. Pro přímou registraci přes interní bránu použijte spolu s `-EnrollmentServer` parametr `-EnrollmentDirect`. Další režimy a ověření účtem počítače popisuje [nastavení proxy](../README.md#připojení-přes-proxy).

Při nové instalaci na stejný doménový účet počítače vydá brána nový token. Starší token okamžitě ztratí platnost. Aktualizace pomocí `-Release` při zachování složky v ProgramData ponechá token i frontu. Při vyřazení odstraňte stanici z AD skupiny a v centrále použijte **Odebrat**. První krok uzavře možnost další registrace, druhý okamžitě odvolá dosud platný token. Centrála uchovává odvolání podle celého principálu včetně realmu i při reinstalaci. Pro náhradu odvolané stanice evidujte nový doménový účet s novým jménem.

## Integrační test brány

Po sestavení obrazů centrály spusťte `APP_IMAGE=dochazka:php-local WEB_IMAGE=dochazka:web-local bash tests/gpo.sh`. Test vytvoří samostatný Compose projekt s dočasným řadičem Samba AD, povoleným a nepovoleným účtem počítače a oběma režimy brány. Ověří opakovanou instalaci na Debianu, Kerberos, LDAPS, HTTPS k centrále, dočasný podpis a odmítnutí opakovaného použití, vydání a výměnu tokenu i odvolání přístupu. Na konci odstraní dočasnou doménu a databázi.
