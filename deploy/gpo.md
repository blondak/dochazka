# Automatická registrace stanic v Samba AD

Spouštěcí GPO v kontextu počítače spustí instalátor jako LocalSystem. Windows předá interní registrační bráně přes Kerberos identitu stanice. Brána ověří přes LDAPS, že jde o počítač ve zvolené doméně a přímého člena povolené skupiny. Centrála vydá token jen této stanici. Při dalších startech instalátor použije uloženou konfiguraci. [Účty počítačů a LocalSystem](https://learn.microsoft.com/en-us/entra/architecture/service-accounts-computer)

## Připravte bránu

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
GPO_IMAGE=ghcr.io/blondak/dochazka:enroll-0.0.1
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

1. Rozbalte Windows release na sdílení čitelné účty počítačů. Zápis vyhraďte správcům distribuce. V adresáři centrály přiřaďte Windows SID zaměstnance, který bude pilotní stanici používat. Svůj SID zjistí pomocí `whoami /user`.
2. V GPO v **Computer Configuration → Windows Settings → Scripts → Startup** nastavte spouštěcí PowerShell skript níže. Pro počítače, jež dostávají doménovou síť až po startu, nastavte ve stejné politice čekání na síť při startu a přihlášení. Při opakovaném běhu po dokončené registraci se použije existující token.

~~~powershell
& '\\server\dochazka\agent-v0.0.1\Install-Agent.ps1' -Server 'https://dochazka.example.cz' -EnrollmentServer 'https://enroll.example.cz' -PackagePath '\\server\dochazka\agent-v0.0.1' -Release '0.0.1'
~~~

3. Povolte úspěšné audity **Logon** i **Logoff** v Advanced Audit Policy. Restartujte pilotní stanici, ověřte službu `DochazkaAgent` a položku `PILOT01$@EXAMPLE.CZ` na kartě **Zařízení**. Proveďte místní interaktivní přihlášení a odhlášení již evidovaného zaměstnance. Po odeslání další dávky musí výkaz ukázat interval mezi těmito událostmi. Služba sleduje interaktivní a cached-interactive události 4624/4634, spánek a probuzení 42/107 v protokolu Kernel-Power. Zámek obrazovky sám směnu neukončuje. Při odpojení sítě se data ukládají do místní SQLite fronty. Po návratu spojení je agent znovu odešle.
4. Pokud chybí vazba na zaměstnance, centrála na kartě **Zařízení** zobrazí upozornění s nepřiřazeným SID. Doplňte jej v adresáři. Agent doručení zopakuje každou hodinu a pokouší se o přiřazení po dobu 30 dnů od prvního odmítnutí. Chyby sběru a spojení najdete také v aplikačním protokolu Windows pod zdrojem `DochazkaAgent`.

## Obnova a vyřazení

Pokud pilotní stanice potřebují proxy, přidejte do startup skriptu `-ProxyUri 'http://proxy.example.cz:3128'`. Volba se použije pro registraci i odesílání událostí. Další režimy a ověření účtem počítače popisuje [nastavení proxy](../README.md#připojení-přes-proxy).

Při nové instalaci na stejný doménový účet počítače vydá brána nový token. Starší token okamžitě ztratí platnost. Aktualizace pomocí `-Release` při zachování složky v ProgramData ponechá token i frontu. Při vyřazení odstraňte stanici z AD skupiny a v centrále použijte **Odebrat**. První krok uzavře možnost další registrace, druhý okamžitě odvolá dosud platný token. Centrála uchovává odvolání podle celého principálu včetně realmu i při reinstalaci. Pro náhradu odvolané stanice evidujte nový doménový účet s novým jménem.

## Integrační test brány

Po sestavení obrazů centrály spusťte `APP_IMAGE=dochazka:php-local WEB_IMAGE=dochazka:web-local bash tests/gpo.sh`. Test vytvoří samostatný Compose projekt s dočasným řadičem Samba AD, povoleným a nepovoleným účtem počítače a bránou používající vlastní keytab a LDAPS. Prověří vydání i výměnu tokenu, zakázané registrace a revokaci. Na konci odstraní dočasnou doménu a databázi.
