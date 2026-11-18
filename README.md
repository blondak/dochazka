# Docházka

Centrála eviduje pracovní dobu z událostí stanic Windows. Zaměstnanci vidí své výkazy a podávají žádosti, vedoucí schvalují záznamy svého týmu a mzdová účtárna uzavírá období a stahuje číslované verze CSV.

Žádost o návštěvu lékaře lze zadat předem i zpětně. Po schválení vedoucím a po skončení návštěvy zaměstnanec na kartě **Žádosti** vybere fotografii nebo ji pořídí fotoaparátem telefonu. Náhled nabízí detekci okrajů a narovnání v prohlížeči před uložením. Před schválením měsíčního výkazu musí být propustka doložená. Vlastní žádosti a výkaz vedoucího schvaluje jiný vedoucí stejného týmu. Pokud vedoucí eviduje také vlastní docházku, přidělte druhého vedoucího při nastavení týmu.

Mzdová účtárna uzavře měsíc po schválení všech zahrnutých osob. Pokud stanice později doručí záznam při práci offline, vedoucí znovu schválí změněný výkaz a účtárna může vydat další verzi CSV. Dříve vydané verze zůstávají dostupné v historii uzávěrky.

## Nasazení centrály

Rozbalte archiv **central-deploy.tar.gz** z vydání centrály a ve stejném adresáři vytvořte soubor **.env**. Nastavte jméno existující sítě Traefiku, heslo PostgreSQL a oba obrazy uvedené v poznámkách vydání. Instalační kód vygenerujte příkazem **openssl rand -hex 32**.

~~~dotenv
TRAEFIK_NETWORK=traefik
DB_PASSWORD=sem-vlozte-vygenerovane-heslo
APP_IMAGE=ghcr.io/blondak/dochazka:php-0.0.4
WEB_IMAGE=ghcr.io/blondak/dochazka:web-0.0.4
SETUP_TOKEN=sem-vlozte-vygenerovany-kod
PASSKEY_ORIGIN=https://dochazka.example.cz
~~~

~~~sh
docker compose -f compose.yaml -f deploy/compose.traefik.yaml pull
docker compose -f compose.yaml -f deploy/compose.traefik.yaml up -d --no-build
~~~

Traefik směruje na **http://dochazka-web:80**. Toto jméno funguje na sdílené Docker síti. Nginx obsluhuje frontend a dokumentaci a předává API interní službě PHP-FPM. Aplikace spouští migrace při startu. Dokumentace Stoplight Elements je dostupná na **/docs/**. Popis **/openapi.json** generuje při sestavení plugin Apitte z atributů controllerů.

Pro nasazení nejnovějšího stabilního vydání použijte jako dvojici `ghcr.io/blondak/dochazka:php-latest` a `ghcr.io/blondak/dochazka:web-latest`. Po vydání se oba tagy posunou na nové obrazy. Nasazení konkrétní verze použije číslované tagy z ukázky výše.

Při prvním otevření webu průvodce požádá o instalační kód a údaje správce. Po založení účtu se kód už nedá použít. Správce po přihlášení uvidí kartu **První kroky**.

Konzolové příkazy spravuje Contributte Console. Správce lze založit i příkazem **docker compose exec app php bin/console account:admin spravce**. Heslo zadáte ze standardního vstupu. Další příkazy jsou **db:migrate** a **api:openapi**.

## Lokální testovací prostředí

Z kořene repozitáře spusťte **bash bin/test-environment.sh up** a otevřete **http://localhost:8080**. Skript vytvoří soukromý soubor **.env.test** s instalačním kódem **SETUP_TOKEN** a samostatnou databázi. Stejný příkaz použijte i při sestavení nové verze. Příkaz **bash bin/test-environment.sh stop** služby zastaví a ponechá testovací data.

## Webové účty a API

Správce v adresáři založí zaměstnance a nastaví uživatelské jméno, tým, jednu či více rolí a Windows SID. Kombinace rolí **Vedoucí** a **Mzdová účetní** zpřístupní uzávěrku i schvalování vlastního týmu. Vedoucí může zobrazit propustky pouze ve svém týmu a vlastní propustky.

Volba **Zpracovávat docházku** určuje, zda centrála pro účet přijímá nové docházkové události a zahrnuje jeho výkaz do uzávěrek. Při zapnutí je povinné osobní číslo pro mzdový export. Technický účet s vypnutou volbou se může přihlašovat a používat přidělené role i bez osobního čísla. Archivace ihned ukončí přístup k účtu a uzavře období evidence nejpozději k datu archivace. Žádosti, výkazy a vydané exporty zůstávají uložené. Správce může účet zobrazit volbou **Zobrazit archivované** a zrušit archivaci.

Tlačítko **Pozvánka** vytvoří jednorázový kód s platností 48 hodin. Zaměstnanec zvolí **Mám pozvánku**, nastaví heslo a přihlásí se. Při zapomenutí hesla může správce vydat novou pozvánku.

Po změně hesla zůstává přihlášený aktuální prohlížeč. Ostatní relace vyžadují nové přihlášení. Po nastavení hesla z nové pozvánky se ukončí všechny staré relace a uživatel si zaregistruje passkeys znovu.

V **Nastavení účtu** může zaměstnanec změnit heslo nebo po jeho ověření přidat a odebírat passkeys. Volitelné **PASSKEY_ORIGIN** nastavte na přesnou adresu webu včetně schématu a případného portu. Passkeys jsou vázané na doménu. Lokální testovací profil je zapíná pro adresu na **localhost**.

Apitte po rozpoznání routy spouští **ApiAccessMiddleware**. Web používá serverovou relaci a hlavičku **X-Dochazka: web**. Atributy Nepada **#[LoggedIn]** a **#[Role(...)]** deklarují požadavky metody. Služby zohledňují vlastníka záznamu a tým. Windows agent používá token stanice v hlavičce **Authorization: Bearer**. Centrála páruje Windows události se zaměstnancem podle SID.

Nette DI konfiguruje **nettrine/dbal** a **nettrine/orm**. Entity jsou v jednotlivých doménách pod **src/**. DBAL obsluhuje například idempotentní příjem událostí a sestavování výkazů s přesností uložených časů. Schéma spravují SQL migrace v **migrations/**.

### Denní přehled a spojování úseků

Vedoucí v **Denním přehledu** vidí stav členů svého týmu podle dnešních událostí Windows a součet uzavřených pracovních relací. Servisní přihlášení do součtu ani do stavu přítomnosti nevstupuje.

Správce v **Nastavení docházky** určí nejdelší mezeru pro automatické spojení uzavřených relací na stejném počítači a v jednom místním dni. Výchozí hodnota je 0 a změna platí ode dne nastavení. Spojování respektuje schválené přestávky, nepřítomnosti a servisní relace.

Vedoucí může ve výkazu člena týmu zaškrtnout pracovní úseky jednoho dne a potvrdit spojení od nejdřívějšího začátku do nejpozdějšího konce. Oprava se uloží mezi žádosti jako **Spojení pracovních úseků**, kde ji vedoucí může odvolat.

## Windows agent v doméně Samba AD

Pro hromadné nasazení předá GPO instalačnímu skriptu adresu centrály a registrační brány. Stanice se ověří vlastním účtem počítače. Registraci mohou provést členové vybrané skupiny v AD. Přípravu brány, GPO, aktualizaci a odvolání přístupu popisuje [návod pro Samba AD](deploy/gpo.md).

Vyberte jeden z balíčků pro Windows x64. **`agent-v0.0.4-win-x64-single-file.zip`** obsahuje agenta jako jediný `Dochazka.Agent.exe`. **`agent-v0.0.4-win-x64.zip`** obsahuje EXE a knihovny v samostatných souborech. Oba balíčky obsahují .NET runtime a `Install-Agent.ps1` pro GPO.

Každý balíček rozbalte do vlastní složky. Při přechodu mezi variantami stejné verze změňte `-PackagePath` i `-Release`. Pro single-file balíček použijte `-Release '0.0.4-single-file'` a pro balíček s DLL použijte `-Release '0.0.4'`.

### Připojení přes proxy

Režim proxy standardně platí pro první registraci i následné odesílání událostí. Na již nainstalované stanici se změna projeví po opětovném spuštění služby. Token a fronta zůstanou dostupné.

Pokud má stanice přímý přístup k interní bráně a centrálu kontaktuje přes systémovou proxy, použijte `-EnrollmentServer` spolu s `-EnrollmentDirect -ProxyMode System`. Přímé spojení se použije pro registraci na bráně. Služba pro odesílání docházky použije režim `System`.

| Nastavení instalátoru | Připojení |
| --- | --- |
| `-ProxyMode System` | Výchozí režim. Registrace ve Windows PowerShell 5.1 použije výchozí Windows proxy. Služba .NET nejprve zohlední `HTTPS_PROXY`, případně `ALL_PROXY` a `NO_PROXY`, potom proxy profilu, pod kterým služba běží. |
| `-ProxyUri 'http://proxy.example.cz:3128'` | Výslovně zadaná HTTP proxy pro spojení HTTPS s centrálou a registrační bránou. |
| `-ProxyMode Direct` | Přímé připojení. |

Pokud proxy podporuje integrované ověření Windows, přidejte `-ProxyUseDefaultCredentials`. Při spuštění z počítačové GPO pod LocalSystem musí proxy povolit účet počítače. Do adresy proxy se uživatelské jméno a heslo nezadávají. Nastavení služby se řídí kontextem LocalSystem, proto ověřte připojení pod tímto účtem. [Výchozí proxy .NET](https://learn.microsoft.com/en-us/dotnet/api/system.net.http.httpclient.defaultproxy?view=net-10.0) · [Parametry Windows PowerShell](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.utility/invoke-restmethod?view=powershell-5.1)

### Ruční registrace stanice

1. Rozbalte balíček **agent-v0.0.4-win-x64.zip** do složky, kterou mohou číst počítačové účty. Zápis vyhraďte správcům distribuce.
2. V centrále na kartě **Zařízení** zaregistrujte stanici. Kód uložte jako **JMENO-POCITACE.txt** do adresáře s registracemi. Na sdílení dejte každému počítačovému účtu právo číst jen jeho vlastní kód. Registraci proveďte během hodinové platnosti.
3. Při pilotu spusťte příkaz níže jako správce. Pro nasazení přes Samba AD nastavte stejný příkaz jako spouštěcí PowerShell skript v konfiguraci počítače v doménové GPO. Při aktualizaci změňte **PackagePath** a **Release**. Služba si ponechá lokální frontu i registraci.
4. V GPO povolte úspěšný audit přihlášení a odhlášení. Na pilotní stanici ověřte párování událostí 4624 a 4634 v protokolu Security a postup při práci bez připojení. SID přihlášeného zaměstnance zjistíte příkazem **whoami /user**.

~~~powershell
& '\\server\dochazka\agent-v0.0.4\Install-Agent.ps1' -Server 'https://dochazka.example.cz' -PackagePath '\\server\dochazka\agent-v0.0.4' -Release '0.0.4' -EnrollmentDirectory '\\server\dochazka-registrace'
~~~

## Vydání

| Tag v repozitáři | Výstup |
| --- | --- |
| [agent-v0.0.4](https://github.com/blondak/dochazka/releases/tag/agent-v0.0.4) | Dva balíčky Windows x64 a kontrolní součty SHA256. CI ověřuje self-test i skutečné spuštění služby pro oba balíčky. |
| [docker-v0.0.4](https://github.com/blondak/dochazka/releases/tag/docker-v0.0.4) | Nasazovací archiv `central-deploy.tar.gz` a obrazy `php-0.0.4`, `web-0.0.4` a `enroll-0.0.4` v GitHub Container Registry. Archiv obsahuje i instalátor brány na Debianu. |

Integrační test **tests/workflow.py** potřebuje PHP 8.4 a přístup k testovací PostgreSQL přes proměnné **PGHOST**, **PGPORT**, **PGDATABASE**, **PGUSER** a **PGPASSWORD**. Vytvoří vlastní dočasné schéma. Samostatný test výpočtu výkazu spustíte jako **php tests/timesheet.php**.

Měření pokrytí PHP spustí `bash tests/coverage.sh` s rozšířením PCOV. Proměnná `PHP_BIN` určuje binární soubor PHP. Pro test přihlášení virtuální passkey nastavte `WEBAUTHN_BROWSER=1`. Testy rozhraní používají Chromium z Playwrightu. Po `npm ci --prefix web` jej nainstalujete příkazem `npm exec --prefix web -- playwright install chromium` a sadu spustíte pomocí `npm run test:e2e --prefix web`. Vlastní Chromium lze zvolit přes `PLAYWRIGHT_CHROMIUM_EXECUTABLE`.

Výsledky ověření jednotlivých částí a zbývající provozní zkoušky shrnuje [audit docházky](docs/audit.md).

Databázová záloha zahrnuje i fotografie propustek a všechny vydané verze mzdového CSV. Postup obnovení do oddělené centrály popisuje [záloha a obnova](deploy/backup.md).
