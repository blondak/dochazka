# Kontrola požadavků z 18. listopadu 2026 po 18. hodině

Časy odpovídají zadáním v pražském časovém pásmu. Stav se vztahuje k místnímu vývoji centrály a agenta.

| Zadání | Stav a způsob ověření |
| --- | --- |
| 18:14–18:17 · Týmový přehled, spojování a podobné relace | Denní přehled vybírá evidované zaměstnance ve větvi vedoucího. Nastavitelná mezera spojuje pracovní úseky a vedoucí může potvrdit ruční spojení v rámci jednoho dne. Zobrazení výkazu správcem samo o sobě neurčuje členství v týmu. Přiřazení zaměstnankyně z produkčního snímku nebylo ověřeno. |
| 18:19–18:20 · Přihlášení, odhlášení a duplicity | Server přijímá jednotlivé události a z nich skládá intervaly. Opakované doručení stejné události zpracuje jednou. Dvě různé relace Windows se stejným zobrazeným časem mohou zůstat v detailu. Překryvy se do pracovní doby započtou jednou. Změna agenta vyhrazuje na odeslání při vypnutí celkem pět sekund. Zbývá ověření skutečným vypnutím Windows. |
| 18:22, 19:12 · Umístění relací | Podle pozdějšího zadání se relace zobrazují v detailu vybraného dne. Servisní relace lze rozlišit a filtrovat podle oprávnění. |
| 18:40 · Červené běhy GitHub Actions | Vydání Dockeru 0.0.3 selhalo. Vydání Dockeru i agenta 0.0.4 prošlo. Samostatný Verify u tagu Dockeru 0.0.4 selhal při čekání na zobrazení dovolené. Tentýž commit na main prošel. Navazující funkce shrnuje provozní dokumentace v README. [Vydání Dockeru](https://github.com/blondak/dochazka/actions/runs/44351484316) · [Vydání agenta](https://github.com/blondak/dochazka/actions/runs/44350515903) · [Verify tagu](https://github.com/blondak/dochazka/actions/runs/44351484250) |
| 18:52–19:03 · Organizační struktura a lokální vývoj | Zaměstnanec má vazbu na jednotku, vedoucí spravuje i podřízené jednotky. Vite poskytuje lokální vývoj s obnovou při změně a proxy k místnímu API. Gedmo bylo posouzeno, strom používá Doctrine a vazbu na nadřazenou jednotku. |
| 19:08–19:16 · Rozložení měsíce a navigace | Na širokém displeji je vedle měsíční tabulky detail, na mobilu pod ní. Celý řádek reaguje na výběr. Tabulka má kompaktní řádky bez vlastního svislého posuvníku, dnešek je označený, evidované víkendy zvýrazněné. Doby se vypisují jako hodiny a dvě číslice minut. Dole je tabulka souhrnů jednotlivých kont. První kroky se nabízejí do naplnění odkazovaných agend. |
| 19:18–19:32 · Práce vedoucího a zaměstnanci | Vedoucí může s uvedením důvodu přidat záznam a upravit nebo zkrátit uznaný pracovní úsek. Audit uchovává změnu a původní relace zůstávají v detailu. Výkaz ořezává měsíční tabulku podle období evidence. Editační dialog předvyplňuje místní časy a používá výběr data a času. Správce otevře zaměstnance z výkazu. Boční menu obsahuje celou navigaci i položky nastavení. |
| 19:34–19:44 · Žádosti, konta a pracovní modely | Čekající a zamítnuté žádosti mají značku u dne. Stav i rozhodovací dialog jsou v detailu. Konta mají typ, doklad a mzdový kód. Verze týdenního modelu a jeho přiřazení určují délku směny a čerpání dovolené. Výpočet, autorizaci, uzávěrku, export a práci s formuláři pokrývají databázové a prohlížečové scénáře. |
| Navazující úpravy · Výkaz a adresář | Výkaz se otevře na dnešku, pokud spadá do zvoleného měsíce a evidence. Nad oběma sloupci je lišta s osobou, obdobím a přidáním konta vlevo a stavem výkazu a počtem čekajících žádostí vpravo. Akce spojení je pod úseky vybraného dne před žádostmi. Kliknutím na jméno v záhlaví se otevře nabídka s nastavením účtu. Odhlášení má samostatnou ikonu. Vedoucí v dialogu Přidat konto vybere i dovolenou nebo lékaře. V organizačním stromu vede odkaz na zaměstnance vybrané větve. V dialogu zaměstnance má SID vlastní široký řádek a obě data jsou na široké obrazovce vedle sebe. |
| Navazující úpravy · Svátky | Datové migrace přidávají konto Svátek a číselník s platností od–do. Pevné datum a posun od velikonoční neděle určují výskyt pro konkrétní rok. Detail dne zvýrazňuje název svátku nebo firemního volna štítkem s ikonou kalendáře. Správce nastavuje výchozí konto, může určit výjimku u jednotlivého svátku a samostatné konto u firemního volna včetně celozávodní dovolené. Kontrolní běh doplní jen prázdnou směnu. Servisní přihlášení ho neblokuje, běžné přihlášení ruší celý automatický zápis. |

## Ověření v místním prostředí

- V prohlížeči nad skutečným Nginxem, PHP-FPM a PostgreSQL prošel cyklus zaměstnanec → vedoucí → účtárna, včetně propustky a CSV. Po obnovení databázové zálohy test porovnal vydané exporty i uloženou fotografii.
- Databázové scénáře ověřily výpočet firemní dovolené, přílohu pro automatický zápis lékaře, dva souběžné kontrolní běhy, pozdní přenos události i změnu běžné relace na servisní a zpět. PCOV naměřilo 1 776 z 2 033 spustitelných řádků PHP (87,4 %). V doméně docházky to bylo 92,7 %, u identity 60,0 % a u zařízení 61,7 %. Pro frontend a Windows agenta jsou k dispozici testovací scénáře.
- Lokální backend má migrace 018 až 021, které přidávají konto Svátek, pravidla kalendáře, ukládání automatických zápisů a jejich přílohy. Náhled kontroly v místních datech za 18. října až 17. listopadu 2026 vrátil nula změn.

## Co zbývá ověřit v provozu

- Spojení Windows relací do uznané doby lze ověřit testy z událostí. Skutečný okamžik odhlášení při vypnutí pilotního počítače závisí na doručení události Windows a dostupnosti sítě.
- Příčinu prázdného produkčního týmu lze potvrdit porovnáním přiřazených jednotek vedoucího a zaměstnance a období jeho evidence.
- Částečná dovolená umožňuje první nebo druhou polovinu čisté směny a vlastní délku od začátku nebo konce směny. U směny 8:00–12:00 čerpá první polovina 8:00–10:00. Náhled přeskočí neplacenou přestávku a souhlas zaměstnance se uloží k žádosti. [MPSV, čerpání dovolené a § 218 odst. 6](https://ppropo.mpsv.cz/X41Cerpanidovolene)

Technické scénáře jsou v [testech výpočtu](../tests/timesheet.php), [HTTP a databázových testech](../tests/workflow.py) a [testech rozhraní](../web/tests/attendance.spec.mjs).
