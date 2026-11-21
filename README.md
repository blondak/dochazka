# Docházka

Centrála eviduje pracovní dobu z událostí stanic Windows. Zaměstnanci vidí své výkazy a podávají žádosti, vedoucí schvalují záznamy svého týmu a mzdová účtárna uzavírá období a stahuje číslované verze CSV.

Žádost o návštěvu lékaře lze zadat předem i zpětně. Po schválení vedoucím a po skončení návštěvy zaměstnanec na kartě **Žádosti** vybere fotografii nebo ji pořídí fotoaparátem telefonu. Náhled nabízí detekci okrajů a narovnání v prohlížeči před uložením. Před schválením měsíčního výkazu musí být propustka doložená. Vlastní žádosti a výkaz vedoucího schvaluje jiný vedoucí ve stejné nebo nadřazené organizační jednotce.

Mzdová účtárna uzavře měsíc po schválení všech zahrnutých osob. Pokud stanice později doručí záznam při práci offline, vedoucí znovu schválí změněný výkaz a účtárna může vydat další verzi CSV. Dříve vydané verze zůstávají dostupné v historii uzávěrky.

## Nasazení centrály

Rozbalte archiv **central-deploy.tar.gz** z vydání centrály a ve stejném adresáři vytvořte soubor **.env**. Nastavte jméno existující sítě Traefiku, heslo PostgreSQL a oba obrazy uvedené v poznámkách vydání. Instalační kód vygenerujte příkazem **openssl rand -hex 32**.

~~~dotenv
TRAEFIK_NETWORK=traefik
DB_PASSWORD=sem-vlozte-vygenerovane-heslo
APP_IMAGE=ghcr.io/blondak/dochazka:php-0.0.6
WEB_IMAGE=ghcr.io/blondak/dochazka:web-0.0.6
SETUP_TOKEN=sem-vlozte-vygenerovany-kod
PASSKEY_ORIGIN=https://dochazka.example.cz
~~~

~~~sh
docker compose -f compose.yaml -f deploy/compose.traefik.yaml pull
docker compose -f compose.yaml -f deploy/compose.traefik.yaml up -d --no-build
~~~

Traefik směruje na **http://dochazka-web:80**. Toto jméno funguje na sdílené Docker síti. Nginx obsluhuje frontend a dokumentaci a předává API interní službě PHP-FPM. Aplikace spouští migrace při startu. Dokumentace Stoplight Elements je dostupná na **/docs/**. Popis **/openapi.json** generuje při sestavení plugin Apitte z atributů controllerů.

Pro nasazení nejnovějšího stabilního vydání použijte jako dvojici `ghcr.io/blondak/dochazka:php-latest` a `ghcr.io/blondak/dochazka:web-latest`. Po vydání se oba tagy posunou na nové obrazy. Nasazení konkrétní verze použije číslované tagy z ukázky výše.

Při prvním otevření webu průvodce požádá o instalační kód a údaje správce. Po založení účtu se kód už nedá použít. **První kroky** zůstávají v bočním menu, dokud chybí organizační jednotka, nearchivovaný zaměstnanec se zpracováním docházky nebo neodvolané zařízení.

Konzolové příkazy spravuje Contributte Console. Správce lze založit i příkazem **docker compose exec app php bin/console account:admin spravce**. Heslo zadáte ze standardního vstupu. Další příkazy jsou **db:migrate** a **api:openapi**.

## Lokální testovací prostředí

Z kořene repozitáře spusťte **bash bin/test-environment.sh up** a otevřete **http://localhost:8080**. Skript vytvoří soukromý soubor **.env.test** s instalačním kódem **SETUP_TOKEN** a samostatnou databázi. Stejný příkaz použijte i při sestavení nové verze. Příkaz **bash bin/test-environment.sh stop** služby zastaví a ponechá testovací data.

Pro vývoj Vue spusťte z kořene repozitáře `npm ci --prefix web` a `npm run dev --prefix web` (alias `npm run watch --prefix web`). Otevřete **http://localhost:5173**. Vite načítá změny přímo z pracovního adresáře a předává požadavky na API lokální centrále na portu 8080. Jiný backend nastavíte proměnnou `DOCHAZKA_DEV_BACKEND`. Pro test passkeys z vývojové adresy spusťte centrálu pomocí `PASSKEY_ORIGIN=http://localhost:5173 bash bin/test-environment.sh up`.

### Instalace Docházky na telefon a počítač (PWA)

Otevřete HTTPS adresu centrály. V Chrome nebo Edge použijte nabídku instalace v adresním řádku nebo v menu prohlížeče. Na iPhonu v Safari zvolte **Sdílet → Přidat na plochu** a zapněte **Otevřít jako webovou aplikaci**, pokud se tato volba zobrazí. Z ikony **Docházka** se pak aplikace otevře ve vlastním okně. [Návod pro instalaci webových aplikací](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable)

Výkazy, žádosti a propustky se načítají z centrály a jejich odeslání vyžaduje připojení. Po prvním otevření online se při dalším spuštění bez spojení zobrazí stránka **Docházka bez připojení**. Po obnově sítě vás tlačítko **Zkusit znovu** vrátí na původní adresu. Service worker se registruje v produkční sestavě. Lokálně jej lze vyzkoušet také na localhostu.

## Webové účty a API

Boční **Menu** zpřístupňuje výkaz, žádosti a další sekce podle role. **Nastavení** sdružuje správu zaměstnanců, organizační struktury, zařízení a pravidel docházky.

V sekci **Organizační struktura** správce vytvoří jednotky a určí jejich rodiče. Původní týmy migrace převede na samostatné jednotky nejvyšší úrovně. Přesun celé větve nebo zaměstnance okamžitě změní, kteří vedoucí mohou otevřít také jeho historické výkazy, žádosti a propustky. Dosavadní rozhodnutí si ponechají původního schvalovatele. Ikona zaměstnanců u jednotky otevře adresář filtrovaný na celou její větev. Smazat lze pouze prázdnou jednotku.

Správce v adresáři založí zaměstnance a nastaví uživatelské jméno, organizační jednotku, jednu či více rolí a Windows SID. Kombinace rolí **Vedoucí** a **Mzdová účetní** zpřístupní uzávěrku i schvalování vlastního týmu. Vedoucí může zobrazit propustky členů své organizační větve i své vlastní.

Role **Personalista** (`hr`) zpřístupňuje zaměstnance, pracovní modely a fond pracovní doby celé organizace. Personalista může založit personální záznam, upravit údaje zaměstnance a přiřadit mu rozvrh. U nového záznamu správce doplní přihlašovací jméno, případný Windows SID a vydá pozvánku. Správce také přiděluje role, přesouvá vedoucí a archivuje účty. Ke schvalování a k propustkám členů týmu opravňuje role **Vedoucí** v odpovídající organizační větvi.

Volba **Zpracovávat docházku** určuje, zda centrála pro účet přijímá nové docházkové události a zahrnuje jeho výkaz do uzávěrek. Při zapnutí je povinné osobní číslo pro mzdový export. Technický účet s vypnutou volbou se může přihlašovat a používat přidělené role i bez osobního čísla. Archivace ihned ukončí přístup k účtu a uzavře období evidence nejpozději k datu archivace. Žádosti, výkazy a vydané exporty zůstávají uložené. Správce může účet zobrazit volbou **Zobrazit archivované** a zrušit archivaci.

Tlačítko **Pozvánka** vytvoří jednorázový kód s platností 48 hodin. Zaměstnanec zvolí **Mám pozvánku**, nastaví heslo a přihlásí se. Při zapomenutí hesla může správce vydat novou pozvánku.

Po změně hesla zůstává přihlášený aktuální prohlížeč. Ostatní relace vyžadují nové přihlášení. Po nastavení hesla z nové pozvánky se ukončí všechny staré relace a uživatel si zaregistruje passkeys znovu.

V **Nastavení účtu** může zaměstnanec změnit heslo nebo po jeho ověření přidat a odebírat passkeys. Volitelné **PASSKEY_ORIGIN** nastavte na přesnou adresu webu včetně schématu a případného portu. Passkeys jsou vázané na doménu. Lokální testovací profil je zapíná pro adresu na **localhost**.

Apitte po rozpoznání routy spouští **ApiAccessMiddleware**. Web používá serverovou relaci a hlavičku **X-Dochazka: web**. Atributy Nepada **#[LoggedIn]** a **#[Role(...)]** deklarují požadavky metody. Služby zohledňují vlastníka záznamu a tým. Windows agent používá token stanice v hlavičce **Authorization: Bearer**. Centrála páruje Windows události se zaměstnancem podle SID.

Nette DI konfiguruje **nettrine/dbal** a **nettrine/orm**. Entity jsou v jednotlivých doménách pod **src/**. DBAL obsluhuje například idempotentní příjem událostí a sestavování výkazů s přesností uložených časů. Schéma spravují SQL migrace v **migrations/**.

### Tým, fond pracovní doby a uzávěrka

Ve stránce **Můj tým** vidí vedoucí čekající žádosti ze všech měsíců a nedořešené výkazy za vybraný měsíc. Úkol otevře příslušnou žádost nebo den výkazu. Při schvalování absence vidí vedoucí plánované hodiny kolegů ve stejném termínu a souběh s čekajícím nebo schváleným volnem. U doložené časové absence se použije skutečný interval. Zaměstnanec na stránce **Žádosti** vidí jména vedoucích, kteří mohou rozhodnout.

V části **Zastupování vedoucích** si vedoucí zvolí aktivovaného vedoucího ze své nebo nadřazené jednotky a zadá první a poslední den zástupu. Po dobu zástupu najde předané úkoly pod **Předáno zástupci**. Zástupce je uvidí mezi svými úkoly. Zastupování vychází ze stávajícího přístupu obou vedoucích a lze je předčasně odvolat. Vlastní žádost nebo výkaz dál vyřizuje jiný oprávněný vedoucí.

**Fond pracovní doby** ukazuje personalistovi, účtárně a správci měsíční plán, uznanou práci, absence včetně svátků a rozdíl. Vedoucí najde stejný přehled za svou větev v **Můj tým**. Rozdíl se počítá z přesných uznaných dob. Kliknutím na rozdíl otevřete odpovídající výkaz. Při neúplném rozvrhu se zobrazí **Chybí model**.

U již skončené plánované směny vyžaduje schválení výkazu poznámku, pokud je směna zcela nepokrytá nebo schodek překročil toleranci. Výchozí tolerance je 15 minut a správce ji změní v **Nastavení docházky → Tolerance schodku směny**. Ze soupisu dnů k prověření lze otevřít detail. Starší schválení zůstává platné, dokud se nezmění jeho dosavadní podklady.

V části **Fond pracovní doby → Import zůstatků dovolené** lze načíst CSV v UTF-8. První řádek je `osobni_cislo;hodiny`, například druhý řádek `A012;72,5`. Vybrané datum určuje zůstatek **na začátku dne**, včetně přiděleného nároku pro daný rok. Zůstatek má zahrnovat čerpání do předchozího dne. Aplikace sama odečte schválené hodiny od zvoleného dne včetně budoucích rezervací do konce roku. Nový import stejné osoby a data původní hodnotu nahradí. Pokud je kterýkoliv řádek chybný, odmítne se celý soubor.

Při podání i schvalování dovolené se pro každý dotčený rok zobrazí dostupné hodiny, délka žádosti a očekávaný zůstatek. Chybějící zůstatek aplikace označí. Záporný výsledek slouží jako informace pro rozhodnutí vedoucího.

V **Uzávěrce** tlačítko **Změny od posledního exportu** porovná vydané CSV s aktuálními podklady. V historii lze porovnat také dvě po sobě vydané verze. Přehled ukáže původní a nové hodnoty podle osobního čísla, dne a mzdového kódu, včetně změny času při stejné délce práce.

### Denní přehled a spojování úseků

**Denní přehled** ukazuje první přihlášení v daném dni a po skončení přítomnosti poslední doložené odhlášení. Šipky přepínají dny a tlačítko **Dnes** vrací živý stav. U minulých dnů odpovídá stav konci vybraného dne. Ikona kalendáře otevře výkaz daného zaměstnance za měsíc zobrazeného dne.

**Výkaz** zobrazuje pracovní dny v době evidence zaměstnance a víkendy s evidovanými záznamy. Podrobnosti dne obsahují plán směny, pracovní úseky, žádosti se stavem a dostupné relace počítače. Ikony v měsíčním přehledu označují čekající a zamítnuté žádosti. Vedoucí žádost vyřídí v dialogu přímo z detailu dne.

V **Nastavení → Pracovní modely** správce vytvoří týdenní rozvrh a přiřadí ho zaměstnanci od zvoleného dne. U nové směny je předvolená přestávka **Podle pravidla konta**. Pro plán se použije pravidlo konta Práce účinné v daný den a doba se počítá od začátku plánované směny. Při vypnutém pravidle se do fondu započítá celá směna. Volba **Pevná přestávka od–do** umožňuje zadat vlastní časy. Dosavadní modely si ponechají původní nastavení. Novou verzi společného modelu založte s datem, od kterého se má používat.

Svátek doplněný podle kalendáře i celodenní dovolená se ve výkazu a mzdovém exportu vykážou za každý den jednou, s čistou délkou směny a prázdnými časy od–do. Například za směnu 08:00–16:30 s půlhodinovou přestávkou se započte 8 hodin. Dříve uložená dovolená za dobu před přiřazením modelu se dál vykazuje v kalendářních dnech. Před novou žádostí o dovolenou musí mít zaměstnanec přiřazený model.

V **Nastavení → Konta docházky** lze například založit školení jako pracovní konto s vlastním mzdovým kódem. Schválené školení se započte jednou do celkové přítomnosti a v souhrnu se ukáže také samostatně. Nové mzdové CSV používá účinné kódy kont a přidává sloupec `sekundy`. Již vydaná CSV si ponechají původní podobu.

Pracovní modely mají rozbalovací verze rozvrhu. Konkrétní model s účinností nastavíte v dialogu zaměstnance na záložce **Docházka**. Zpětná oprava zobrazí dopady a vyžádá důvod. Ve výkazu může vedoucí upravit uznanou délku úseku s ponechanými časy příchodu a odchodu. Při mzdovém importu použijte sloupec `sekundy`. Interní zaokrouhlení konta se ukazuje ve výkazu vedle přesné uznané doby.

U pracovního konta lze zapnout návrh pauzy podle skutečného příchodu. Vedoucí ji posoudí při schválení výkazu. Po schválení odhadu časového volna zaměstnanec doplní skutečné časy a doloží případnou přílohu. Částečná dovolená se vykazuje jako datum a schválená délka. Vedoucí může kontrolu svátků spustit v detailu dne pro jednotlivce nebo v Denním přehledu pro svou organizační větev.

V **Nastavení → Svátky a dny volna** správce zvolí konto pro české svátky s datem účinnosti. Každý svátek má platnost od–do a pevné datum nebo posun ve dnech od velikonoční neděle. Každé pravidlo může převzít výchozí konto nebo určit své vlastní. Odebrání platného pravidla ukončí jeho platnost dneškem včetně. Pravidlo s budoucím začátkem se odstraní celé. Pro konkrétní firemní den volna lze zadat vlastní název a vybrat libovolné aktivní konto. Při celozávodní dovolené zvolte **Dovolená**, aby hodiny směny vstoupily přímo do souhrnu dovolené a mzdového exportu. Konto s povinným dokladem vyžaduje přílohu také u automatického zápisu. Kontrolní běh zapíše do prázdného svátku nebo firemního volna celou plánovanou směnu po odečtení neplacené přestávky. Pozdější pracovní záznam za stejný den vyřadí celý automatický zápis z výkazu i další uzávěrky, i když se odpracovala jen část směny. Servisní přihlášení doplnění svátku neblokuje. Po zpětném označení jediné relace za servisní může další běh svátek doplnit. Za volný den bez plánované směny se hodiny nepřipisují.

**Kontrolní chod** v horní liště Výkazu platí pro vybraného zaměstnance. V Denním přehledu platí pro celou spravovanou organizační větev. Vedoucí zvolí období a úkon, zobrazí náhled a potvrdí případné změny.

V místním testovacím prostředí spusťte `bash bin/test-environment.sh fill-holidays --dry-run`. V nasazené centrále pro náhled použijte `docker compose exec -T app php bin/console attendance:fill-holidays --dry-run`. Bez `--dry-run` se zapíší změny. Standardně se zpracuje posledních 31 dokončených dnů podle pražského času. Jiné období lze určit pomocí `--from=2026-10-01 --to=2026-10-31` (oba dny včetně). Běh lze bezpečně opakovat. Pro každodenní doplnění lze na hostiteli centrály naplánovat například tento řádek cronu se skutečnou cestou k projektu.

```cron
15 3 * * * cd /cesta/k/centrale && docker compose exec -T app php bin/console attendance:fill-holidays
```

Při otevření aktuálního měsíce výkaz vybere dnešek, pokud spadá do období evidence. **Přidat záznam do výkazu** v liště nad dny a detailem umožní vedoucímu zapsat například přítomnost, lékaře či celou směnu dovolené. Před zápisem dovolené ukáže výkaz čerpání podle pracovního modelu. Zápis vedoucího se rovnou započítá. U pracovního úseku může vedoucí později upravit začátek a konec. Uvede důvod a úprava se objeví v **Žádostech**. Audit ukládá autora, důvod a původní i nový čas. Windows relace zůstávají dostupné podle oprávnění. Uznaná doba vstupuje do výkazu a exportu a upravený výkaz vyžaduje nové schválení.

Vedoucí v **Denním přehledu** vidí stav členů vlastní jednotky a všech podřízených jednotek podle dnešních událostí Windows a součet uzavřených pracovních relací. Servisní přihlášení do součtu ani do stavu přítomnosti nevstupuje.

Správce v **Nastavení docházky** určí nejdelší mezeru pro automatické spojení uzavřených relací na stejném počítači a v jednom místním dni. Výchozí hodnota je 0 a změna platí ode dne nastavení. Spojování respektuje schválené přestávky, nepřítomnosti a servisní relace.

Vedoucí může ve výkazu člena týmu zaškrtnout pracovní úseky jednoho dne a potvrdit spojení od nejdřívějšího začátku do nejpozdějšího konce. Oprava se uloží mezi žádosti jako **Spojení pracovních úseků**, kde ji vedoucí může odvolat.

## Windows agent v doméně Samba AD

Pro hromadné nasazení předá GPO instalačnímu skriptu adresu centrály a registrační brány. Stanice se ověří vlastním účtem počítače. Registraci mohou provést členové vybrané skupiny v AD. Přípravu brány, GPO, aktualizaci a odvolání přístupu popisuje [návod pro Samba AD](deploy/gpo.md).

Vyberte jeden z balíčků pro Windows x64. **`agent-v0.0.6-win-x64-single-file.zip`** obsahuje agenta jako jediný `Dochazka.Agent.exe`. **`agent-v0.0.6-win-x64.zip`** obsahuje EXE a knihovny v samostatných souborech. Oba balíčky obsahují .NET runtime a `Install-Agent.ps1` pro GPO.

Každý balíček rozbalte do vlastní složky. Při přechodu mezi variantami stejné verze změňte `-PackagePath` i `-Release`. Pro single-file balíček použijte `-Release '0.0.6-single-file'` a pro balíček s DLL použijte `-Release '0.0.6'`.

V **Nastavení → Zařízení** uvidíte poslední verzi nahlášenou běžící službou a čas posledního spojení. Agent hlásí verzi při pravidelném spojení s centrálou i bez nových událostí. U stanic, které verzi dosud nenahlásily, se zobrazuje pomlčka.

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

1. Rozbalte balíček **agent-v0.0.6-win-x64.zip** do složky, kterou mohou číst počítačové účty. Zápis vyhraďte správcům distribuce.
2. V centrále na kartě **Zařízení** zaregistrujte stanici. Kód uložte jako **JMENO-POCITACE.txt** do adresáře s registracemi. Na sdílení dejte každému počítačovému účtu právo číst jen jeho vlastní kód. Registraci proveďte během hodinové platnosti.
3. Při pilotu spusťte příkaz níže jako správce. Pro nasazení přes Samba AD nastavte stejný příkaz jako spouštěcí PowerShell skript v konfiguraci počítače v doménové GPO. Při aktualizaci změňte **PackagePath** a **Release**. Služba si ponechá lokální frontu i registraci.
4. V GPO povolte úspěšný audit přihlášení a odhlášení pro dočtení z protokolu Security. Na pilotní stanici ověřte přímá oznámení Windows a doručení po výpadku sítě. SID přihlášeného zaměstnance zjistíte příkazem **whoami /user**.

~~~powershell
& '\\server\dochazka\agent-v0.0.6\Install-Agent.ps1' -Server 'https://dochazka.example.cz' -PackagePath '\\server\dochazka\agent-v0.0.6' -Release '0.0.6' -EnrollmentDirectory '\\server\dochazka-registrace'
~~~

## Vydání

| Tag v repozitáři | Výstup |
| --- | --- |
| [agent-v0.0.6](https://github.com/blondak/dochazka/releases/tag/agent-v0.0.6) | Dva balíčky Windows x64 a kontrolní součty SHA256. CI ověřuje self-test i skutečné spuštění služby pro oba balíčky. |
| [docker-v0.0.6](https://github.com/blondak/dochazka/releases/tag/docker-v0.0.6) | Nasazovací archiv `central-deploy.tar.gz` a obrazy `php-0.0.6`, `web-0.0.6` a `enroll-0.0.6` v GitHub Container Registry. Archiv obsahuje i instalátor brány na Debianu. |

Integrační test **tests/workflow.py** potřebuje PHP 8.4 a přístup k testovací PostgreSQL přes proměnné **PGHOST**, **PGPORT**, **PGDATABASE**, **PGUSER** a **PGPASSWORD**. Vytvoří vlastní dočasné schéma. Samostatný test výpočtu výkazu spustíte jako **php tests/timesheet.php**.

Měření pokrytí PHP spustí `bash tests/coverage.sh` s rozšířením PCOV. Proměnná `PHP_BIN` určuje binární soubor PHP. Pro test přihlášení virtuální passkey nastavte `WEBAUTHN_BROWSER=1`. Testy rozhraní používají Chromium z Playwrightu. Po `npm ci --prefix web` jej nainstalujete příkazem `npm exec --prefix web -- playwright install chromium` a sadu spustíte pomocí `npm run test:e2e --prefix web`. Vlastní Chromium lze zvolit přes `PLAYWRIGHT_CHROMIUM_EXECUTABLE`.

Výsledky ověření jednotlivých částí a zbývající provozní zkoušky shrnuje [audit docházky](docs/audit.md).

Databázová záloha zahrnuje i fotografie propustek a všechny vydané verze mzdového CSV. Postup obnovení do oddělené centrály popisuje [záloha a obnova](deploy/backup.md).
