# Κωδικοποίηση νόμων, ΦΕΚ, ταξινόμηση & πρότυπα — ευρήματα έρευνας

## 1. Μεθοδολογία κωδικοποίησης (consolidation)

- **UK legislation.gov.uk** (✅ primary, verified): μοντελοποιεί νόμο ως **Items →
  Versions → Effects/Commencements**. Κάθε "Version" = το πλήρες κείμενο μιας πρόβλεψης
  όπως ίσχυε σε συγκεκριμένη ημερομηνία. URI σε ημερομηνία που δεν συμπίπτει με αλλαγή
  επιστρέφει το κείμενο της πλησιέστερης αποθηκευμένης έκδοσης — δεν υπολογίζεται diff
  on-the-fly. Τα "Effects" (π.χ. "words substituted", "repealed", "restricted") είναι
  ξεχωριστά, τυπισμένα αντικείμενα-σχέσης (πηγή διάταξη → στόχος διάταξη, τύπος,
  ημερομηνία), tracked ως ξεχωριστό dataset (~1 εκατ. καταγεγραμμένες αλλαγές),
  ερωτήσιμο ανά τύπο/έτος εγγράφου.
- Το document/eId structure tree (πίνακας περιεχομένων) αλλάζει ανά έκδοση καθώς
  προστίθενται/καταργούνται τμήματα — το σύστημα εκθέτει version-specific tables of
  contents, όχι ενιαία στατική δομή.
- Canonical στοιχείο αποθήκευσης στο legislation.gov.uk είναι CLML XML σε native XML
  database· το Akoma Ntoso XML, HTML5, XHTML, PDF παράγονται ως derived formats από αυτό.
- Το data model καταγράφει επίσης ημερομηνίες enactment/making/laying/coming-into-force
  και αν υπάρχουν "unapplied" (prospective) αλλαγές που δεν έχουν ακόμα ενσωματωθεί.
  Συνδέει επίσης το record με σχετικά έγγραφα (Impact Assessments, Explanatory
  Memoranda).
- **EUR-Lex/CELLAR** (secondary): "consolidated texts" ενσωματώνουν τροποποιήσεις σε ένα
  ενιαίο έγγραφο αναφοράς, ρητά χωρίς νομική ισχύ από μόνα τους (ξεχωριστά από το αρχικό
  enactment). CELLAR είναι ενιαίο repository (από αναδιοργάνωση 2014) που κωδικοποιεί τις
  FRBR σχέσεις μέσω RDF properties: `cdm:work_has_expression`,
  `cdm:expression_has_manifestation`, `cdm:consolidated_by`, `cdm:work_related_to`.
- **Akoma Ntoso standard** (✅ primary, OASIS spec): ορίζει το `@contains` attribute με τρεις
  κατηγορίες: `originalVersion` (ως θεσπίστηκε, καμία τροποποίηση), `singleVersion` (μία
  ενοποιημένη "as amended" έκδοση, χωρίς να σημειώνονται ξεχωριστά τα individual
  insertions/deletions), `multipleVersions` (juxtaposition αποσπασμάτων από ≥2 εκδόσεις,
  κάθε ένα με δικό του `@start`/`@end`/`@startEfficacy`/`@endEfficacy`/`@status`, IDREFs
  προς `<lifecycle>` events).
- Amendment tracking στο AKN κεντράρεται σε δύο υποχρεωτικά meta-blocks (για πράξη με ≥2
  events): `<lifecycle>` (λίστα dated events) και `<references>` (URIs των εγγράφων που
  προκάλεσαν τα events).
- AKN διακρίνει "active modifications" (αλλαγές που κάνει ένα έγγραφο σε άλλο) από
  "passive modifications" (αλλαγές που δέχεται), κάθε μία δεσμευμένη σε συγκεκριμένη,
  αντικειμενικά προσδιορίσιμη ημερομηνία και ιχνηλάσιμη σε συγκεκριμένο πηγαίο έγγραφο.
- Τρόποι μαρκαρίσματος τροποποιήσεων στο AKN: (1) `<mod>` + `<quotedText>` για απλή
  αντικατάσταση κειμένου, (2) `<quotedStructure>` με ενσωματωμένα `<ins>`/`<del>` για
  πλήρη επανάληψη τροποποιημένου τμήματος, (3) implicit modifications (αναφορά σε εύρος
  χωρίς ρητή δήλωση αλλαγής). Στο ίδιο το τροποποιημένο έγγραφο, οι αλλαγές μαρκάρονται με
  `<ins>`/`<del>`, με `<omissis>` για παραλειπόμενο κείμενο. Κάθε `<mod>` συνδέεται μέσω
  `@eId` με `<textualMod>` records μέσα σε `<activeModifications>`/`<passiveModifications>`
  στο `<analysis>` meta block.
- (blog πηγή) US states: California απαιτεί πλήρεις "amended-in-full" επαναδιατυπώσεις
  ολόκληρου του τροποποιημένου τμήματος· το Ομοσπονδιακό δίκαιο/Κογκρέσο επιτρέπει
  "cut-and-bite" προσέγγιση με διακριτές, μη-ενσωματωμένες τροποποιήσεις σε λέξεις.
- (Data Foundation, US Congress) Νομικές τροποποιήσεις εκφράζονται συνήθως ως εντολές
  ("strike", "insert", "repeal") που πρέπει να ερμηνευτούν/εφαρμοστούν για να παραχθεί νέο
  ενοποιημένο κείμενο — δεν είναι diffs του ίδιου του κειμένου. Για πολλούς πρόσφατα
  τροποποιημένους νόμους δεν υπάρχει "no current official version and no way to see a
  precise history of amendments over time".
- ❌ **Αναιρεμένος ισχυρισμός** (3× refuted στο verification): ένας αρχικός ισχυρισμός
  βασισμένος στο arXiv 2506.07853 ("LRMoo-Based, Component-Level, Event-Centric Approach")
  υποστήριζε ότι το paper τεκμηριώνει συγκεκριμένη επιλογή snapshot-vs-diff storage. Το
  verification βρήκε ότι το paper είναι καθαρά conceptual/ontological (FRBR/LRMoo-level
  modeling μέσω ενός "F2 Expression" = "Temporal Version"), δεν κάνει καμία σύσταση για
  storage/implementation strategy, και ρητά αναβάλλει tooling/pipeline ζητήματα σε "future
  work". Ό,τι το paper πράγματι λέει: το core AKN schema έχει native versioning μόνο σε
  επίπεδο ολόκληρου εγγράφου, όχι σε επίπεδο μεμονωμένου eId (άρθρο/παράγραφος) — αυτό το
  κομμάτι δεν αναιρέθηκε. Το paper μοντελοποιεί κάθε τροποποίηση ως "F28 Expression
  Creation" event, αποσυντιθέμενο σε macro-event (η τροποποιητική πράξη) και micro-events
  (κάθε συγκεκριμένη αλλαγή σε συγκεκριμένη διάταξη).

## 1α. Πρακτική υλοποίηση — Kenya Law / Laws.Africa (Indigo platform)

- Η Kenya είναι, βάσει της πηγής, η μόνη χώρα στην Αφρική που χρησιμοποιεί machine-friendly
  Akoma Ntoso XML για να κωδικοποιήσει και δημοσιεύσει τη νομοθεσία της, τόσο online όσο
  και σε έντυπη μορφή.
- Η ομάδα του Kenya Law μαρκάριζε χειροκίνητα τη νομοθεσία με AKN για πάνω από 5 χρόνια.
  Προβλήματα που αναφέρθηκαν: "The manual process of marking up legislation with AKN is
  slow and error-prone" και δυσκολία στο "add new functionality, such as rich
  point-in-time navigation". Αν ένας νόμος δημοσιευόταν σε ΦΕΚ (gazetted) μια μέρα, ήταν
  σχεδόν αδύνατο να ενημερωθεί στον ιστότοπο την ίδια μέρα, δημιουργώντας συσσωρευμένη
  καθυστέρηση (backlog). Η χειροκίνητη σελιδοποίηση (pagination) επιβράδυνε επιπλέον τη
  δουλειά.
- Η μετάβαση στην πλατφόρμα **Indigo** (Laws.Africa) στόχευε να μειώσει τον χρόνο
  κωδικοποίησης "from months to days" και να αυτοματοποιήσει editorial workflows και
  Πίνακες Περιεχομένων.
- **Data model του Indigo** (από την τεκμηρίωσή του): διαχωρίζει **"work"** (η ίδια η
  νομοθετική οντότητα, με σταθερά μεταδεδομένα — τίτλος, έτος, χώρα, στοιχεία έναρξης
  ισχύος) από **"document"** (μία συγκεκριμένη έκδοση περιεχομένου σε συγκεκριμένη χρονική
  στιγμή, αποθηκευμένη σε Akoma Ntoso XML). Ρητή αρχή: *"A new document is created each
  time a new amendment must be applied to an existing work."* Και: *"Each amended version
  of the document has a different `id`, but the same work URI."* Η δημόσια API εκθέτει
  FRBR URIs (χωρίς interna document ids), ενώ η εσωτερική application API χρησιμοποιεί
  αριθμητικά document IDs για draft/deleted/amended έγγραφα.
- Το Indigo υποστηρίζει permissions περιορισμένα ανά δικαιοδοσία (jurisdiction) και ανά
  ενέργεια (create/save/publish).

## 2. Αποθήκευση ΦΕΚ / Government Gazette

- **FRBR Manifestation/Item** (✅ primary, OASIS akn-core vocabulary): AKN υιοθετεί το
  IFLA FRBR four-level model: **Work** (αφηρημένος νομικός πόρος), **Expression**
  (συγκεκριμένη έκδοση — γλώσσα/έκδοση/ημερομηνία), **Manifestation** (μορφή — XML, PDF,
  TIFF, Word), **Item** (φυσικό/αποθηκευμένο αντίγραφο). Ένα σκαναρισμένο ΦΕΚ PDF είναι
  Manifestation/Item της ίδιας Work/Expression.
- Το πρότυπο σημειώνει ρητά ότι η δημοσίευση σε επίσημη εφημερίδα δεν τεκμηριώνει από
  μόνη της την αυθεντικότητα του περιεχομένου, καθώς κάποια δεδομένα σε επίπεδο gazette
  (π.χ. αριθμός τεύχους) δεν αποφασίζονται από τον συντάκτη του εγγράφου.
- Metadata οργανώνεται σε 9 υποενότητες· "Publication" κρατά μεταδεδομένα τεύχους/
  ημερομηνίας επίσημης εφημερίδας.
- **legislation.gov.uk**: αποθηκεύει CLML XML ως πηγή αλήθειας και παράγει πολλαπλά
  manifestations (Akoma Ntoso XML, HTML5, XHTML, PDF) από αυτό — Akoma Ntoso συχνά
  λειτουργεί ως interchange/export format, όχι απαραίτητα primary storage.
- **search.et.gr** (Εθνικό Τυπογραφείο, primary πηγή): επίσημος φορέας δημοσίευσης ΦΕΚ,
  με ξεχωριστά search modes (Daily Publications, Simple Search, Advanced Search). Η
  υποβολή προς δημοσίευση γίνεται μέσω ξεχωριστού συστήματος (eservices.et.gr), decoupled
  από το search/retrieval portal. Η στατική landing page δεν απαριθμεί τις σειρές ΦΕΚ σε
  static HTML — φορτώνονται δυναμικά.
- (el.wikipedia, medium confidence) Σειρές/τεύχη ΦΕΚ: Α΄ Νόμοι, Β΄ Κανονιστικές Πράξεις,
  Γ΄ Υπαλληλικά, Δ΄ Απαλλοτριώσεις, ΑΑΠ, ΥΟΔΔ, ΝΠΔΔ, Παράρτημα κ.ά.
- (Wikipedia — Government Gazette (Greece)) Το ΦΕΚ πρωτοεκδόθηκε το 1833· μέχρι το 1835,
  στην περίοδο της αντιβασιλείας του Όθωνα, ήταν δίγλωσσο (ελληνικά/γερμανικά). Ρητή
  διατύπωση για τη νομική ισχύ: *"No law in Greece is valid until its publication in this
  journal."* Κάθε τεύχος χωρίζεται σε "Τεύχος" (Teuchos) με διακριτούς ρόλους. Η Εφημερίδα
  δημοσιεύει επίσης ιδρύσεις, δικαιώματα και υποχρεώσεις νομικών προσώπων.
- (search.et.gr, απευθείας παρατήρηση) Ο ΦΕΚ αναγνωρίζεται πρακτικά μέσω τριάδας
  αριθμός/τεύχος/έτος — π.χ. "ΦΕΚ 91Α΄/1976" ή "ΦΕΚ 884Β΄/19.08.1998". Το search.et.gr
  εκθέτει ξεχωριστή λειτουργία **"Αναζήτηση Νομοθεσίας"** (search-legislation) διακριτή
  από την αναζήτηση ΦΕΚ (Simple/Advanced Search) — δηλαδή το portal διαχωρίζει ήδη
  "αναζήτηση εγγράφου-ΦΕΚ" από "αναζήτηση νομοθετικού περιεχομένου".
- (WebSearch snippet, πηγή πιθανώς Library of Congress "Guide to Law Online: Greece —
  Legislative"· η απευθείας ανάκτηση της σελίδας απέτυχε με HTTP 403, οπότε το εύρημα
  βασίζεται μόνο στο search snippet, χαμηλότερη βεβαιότητα): *"For the time being, no
  official collection of consolidated legislation is available... for the Efimeris tis
  Kiverniseos online, there is no other official legislative database available."*
- **EUR-Lex/CELLAR**: manifestations για έγγραφα από το 2014 και μετά χρησιμοποιούν
  Akoma Ntoso-style eId naming σε XHTML· παλαιότερα έγγραφα χρησιμοποιούν legacy Formex
  XML format. Έγγραφα διατίθενται σε HTML/PDF/XML, σε 24 επίσημες γλώσσες της ΕΕ.

## 3. Ταξινόμηση (classification)

- (✅ primary, OASIS spec) Το AKN `<meta>` block έχει ρητή υποενότητα **"Classification"**
  (μία από τις 9 προβλεπόμενες), που κρατά λέξεις-κλειδιά από ελεγχόμενο λεξιλόγιο όπως το
  **EuroVoc thesaurus**, εφαρμόσιμη σε όλο το έγγραφο ή σε επιμέρους τμήματα.
- (blog πηγή) EUR-Lex ταξινομεί έγγραφα βάσει EuroVoc thesaurus μέσω RDF concept links
  (`cdm:work_is_about_concept_eurovoc`) με αριθμητικά concept IDs.
- (WebSearch, δεν επαληθεύτηκε με απευθείας fetch) Το EuroVoc οργανώνεται σε 21 domains,
  127 microthesauri, και πάνω από 500 top terms, διαθέσιμο σε όλες τις γλώσσες των
  κρατών-μελών της ΕΕ. Χρησιμοποιείται ως classification schema σε δημόσια legal-NLP
  datasets όπως το JRC-Acquis V3 και το EURLEX57K· πρόσφατες υλοποιήσεις δημιουργούν
  συλλογές 8 εκατ. εγγράφων σε 24 γλώσσες tagged με EuroVoc labels από το EUR-Lex.
- Εργαλεία αυτόματης EuroVoc ταξινόμησης νομικών κειμένων που εντοπίστηκαν: JEX, PyEuroVoc,
  KEVLAR — αναφέρονται ως εξέλιξη από βασικά συστήματα ταξινόμησης σε neural
  architectures.

## 4. Πρότυπα αναγνωριστικών (ELI / AKN-NC / ECLI)

- **AKN Naming Convention URI pattern** — ⚠️ ένας αρχικός ισχυρισμός για τη γενική μορφή
  αναιρέθηκε 3× στο verification (τοποθετούσε λάθος το `@` σύμβολο). Το σωστό, όπως
  επιβεβαιώθηκε απευθείας από το κείμενο OASIS `akn-nc-v1.0`:
  - **Work IRI**: `/akn/{country}/{type}/{date}/{number}` — π.χ.
    `/akn/sl/act/2004-02-13/2` (χωρίς γλώσσα/έκδοση).
  - **Expression IRI**: `/` + γλώσσα, μετά `@` + ημερομηνία-έκδοσης — π.χ.
    `/akn/sl/act/2004-02-13/2/eng@2004-07-21`.
  - **Manifestation IRI**: format προστίθεται μετά το Expression IRI, προαιρετικά με
    ενδιάμεσο author/date segment — π.χ. `/akn/sl/act/2004-02-13/2/eng.pdf` ή
    `/akn/sl/act/2004-02-13/2/eng@2004-07-21/CIRSFID/2011-07-15.akn`.
  - Ο σχεδιαστικός στόχος (ρητά δηλωμένος στο spec): τα identifiers να είναι meaningful,
    permanent, invariant ανεξαρτήτως process/tool/προσώπου που τα παρήγαγε.
  - Δημοσιεύτηκε ως επίσημο OASIS Standard στις 21 Φεβρουαρίου 2019 από την
    LegalDocumentML (LegalDocML) Technical Committee.
  - ❌ Επαληθεύτηκε ρητά ότι το ίδιο το κείμενο **δεν** αναφέρει ή ορίζει ευθυγράμμιση με
    το ELI standard — στηρίζεται σε RFC 3987 (IRIs), ISO 3166 (χώρες), ISO 639-2
    (γλώσσες).
- **ELI (European Legislation Identifier)** (✅ primary, EU Publications Office):
  - Ορίζεται ως σύστημα HTTP URIs, αναγνώσιμο από ανθρώπους και μηχανές, με προτεινόμενο
    σύνολο μεταδεδομένων και ειδική γλώσσα ανταλλαγής για νομοθεσία σε μηχαναναγνώσιμη
    μορφή.
  - Canonical pattern (EU-level): `http://data.europa.eu/eli/{typeOfDocument}/
    {yearOfAdoption}/{numberOfDocument}/oj`.
  - Επεκτείνεται και σε υποτμήματα πράξεων (citations, recitals, articles) — π.χ.
    `.../reg/2019/1241/art_2/oj`.
  - Οργανώνεται σε 4 "πυλώνες": URI identification, μεταδεδομένα (ELI ontology/OWL),
    δημοσίευση μεταδεδομένων (RDFa ή JSON-LD), συγχρονισμός μεταδεδομένων (sitemap + Atom
    feed) — μπορούν να υιοθετηθούν μαζί ή σταδιακά.
  - Serialization μεταδεδομένων: RDFa (W3C RDFa in XHTML 2008) ή JSON-LD (W3C JSON-LD 1.1
    2020), ενσωματωμένα στις σελίδες επίσημης εφημερίδας.
  - Υιοθέτηση από κράτη-μέλη είναι **εθελοντική**, όχι υποχρεωτική. Σε επίπεδο ΕΕ, ELIs
    ανατίθενται συγκεκριμένα σε νομοθεσία της σειράς L της Επίσημης Εφημερίδας
    (κανονισμοί, οδηγίες, αποφάσεις) και σε consolidated acts, όχι ομοιόμορφα σε όλους
    τους τύπους εγγράφων.
  - 21 δικαιοδοσίες (εθνικές/ΕΕ) είχαν υλοποιήσει ELI στη βάση 1/1/2023 (Ελλάδα δεν
    αναφέρθηκε στη λίστα που εντοπίστηκε).
  - Νομική βάση, με ακριβή στοιχεία: δύο ξεχωριστά Συμπεράσματα του Συμβουλίου της ΕΕ —
    "Council conclusions inviting the introduction of the European Legislation Identifier
    (ELI)" (2012/C 325/02, Οκτώβριος 2012, αρχική πρόσκληση προς τα κράτη-μέλη) και
    "Council conclusions on the European Legislation Identifier" (2017/C 441/05, 6
    Νοεμβρίου 2017, "building on the conclusions of 2012").
  - (WebSearch, δεν επαληθεύτηκε με απευθείας fetch) Χώρες που είχαν υιοθετήσει ELI:
    Δανία, Φινλανδία, Γαλλία, Ιρλανδία, Ιταλία, Λουξεμβούργο, Νορβηγία, Πορτογαλία,
    Ηνωμένο Βασίλειο, και το Publications Office της ΕΕ — "in different levels" (δηλ. όχι
    όλες οι χώρες σε πλήρη υλοποίηση και των 4 πυλώνων). Η Ελλάδα δεν εμφανίστηκε σε καμία
    λίστα υλοποίησης που εντοπίστηκε σε αυτό το pass.
  - (secondary) ELI μεταδεδομένα βασίζονται σε FRBRoo και CIDOC CRM.
  - (secondary) ELI URI template components: jurisdiction, agent, sub-agent, date, type,
    natural identifier, level, point-in-time, version, language.
- **CELEX** (secondary): legacy αλλά ακόμα υποστηριζόμενο identifier scheme, ξεχωριστό
  από ELI, δομή `[Sector][Year][DocumentType][Number]` (π.χ. `32012L0019`).
- **ECLI (European Case Law Identifier)** (secondary):
  - Μορφή: `ECLI:{country}:{court}:{year}:{ordinal}` (5 στοιχεία, colon-separated).
  - Το unique identifier component περιορίζεται σε γράμματα/ψηφία/τελείες, μέχρι 25
    χαρακτήρες, case-insensitive (συμβατικά κεφαλαία).
  - Work-level identifier για την αφηρημένη δικαστική απόφαση, όχι για συγκεκριμένο
    αρχείο/PDF (Manifestation-level).
  - Decentralized governance: Συμβούλιο Υπουργών ΕΕ εγκρίνει αλλαγές standard, Ευρωπαϊκή
    Επιτροπή διαχειρίζεται το κεντρικό lookup site, κάθε χώρα ορίζει δικό της national
    ECLI coordinator για court codes.
  - Ανομοιόμορφη υιοθέτηση μεταξύ κρατών-μελών· Ισπανία και Ιταλία μπροστά σε όγκο
    indexed αποφάσεων (στοιχεία 2019).
  - Ξεχωριστό standard από ELI (case law vs. νομοθεσία), και τα δύο ενσωματωμένα στο
    EUR-Lex.

## 5. Χρήση wiki engine ως πλατφόρμα κωδικοποίησης — προηγούμενα

- **Extension:FlaggedRevs** (✅ primary, mediawiki.org):
  - Επιτρέπει ορισμό συγκεκριμένου "stable" (reviewed) revision ως προεπιλογή που
    βλέπουν οι αναγνώστες, ξεχωριστό από το τελευταίο edited/draft revision.
  - Η κοινότητα MediaWiki προειδοποιεί ρητά ενάντια στη χρήση του σε production
    ("complex, poorly documented, clunky").
  - Καμία νέα deployment στο Wikimedia από το 2014· deployment moratorium από το 2017.
  - Granular permission model: `review`, `validate`, `autoreview`, `stablesettings`
    rights, οργανωμένα σε ομάδες editor/reviewer/autoreview.
  - Review/stability requirements μπορούν να περιοριστούν ανά namespace μέσω config
    variable.
- **Wikisource — Help:Official texts** (✅ primary):
  - "Official texts" (νομοθετικά, διοικητικά, νομικά κείμενα + επίσημες μεταφράσεις,
    βάσει Berne Convention) αποτελούν ξεχωριστή αποδεκτή κατηγορία περιεχομένου.
  - Το PD (public domain) καθεστώς που χρησιμοποιεί το en.wikisource είναι US-specific
    default, όχι εγγύηση παγκόσμιας δημόσιας κτήσης — μη-US κείμενα χρειάζονται
    jurisdiction-specific έλεγχο πνευματικών δικαιωμάτων πριν τη φιλοξενία.
  - Απαιτείται ανέβασμα scan της πηγής (τοπικά ή στο Wikimedia Commons) ως backing
    artifact για το transcribed κείμενο — όχι μόνο transcription.
  - Επίσημα κείμενα κυβέρνησης ταγκάρονται με country-specific PD/copyright templates,
    όχι γενικό tag.
- **Wikisource:WikiProject UK Law** (forum-quality πηγή, central claims):
  - Περιορισμός λόγω πνευματικών δικαιωμάτων: μόνο η αρχική ("as enacted") έκδοση, ή η
    τελευταία τροποποιημένη έκδοση, μπορούν να αναπαραχθούν, και μόνο αν έχουν δημοσιευτεί
    πριν από τουλάχιστον 50 χρόνια — δηλαδή δεν φιλοξενεί ζωντανή, τρέχουσα
    consolidated/as-amended έκδοση βρετανικού δικαίου.
  - Δεν συντηρεί συστηματικά ενημερωμένες consolidated/as-amended εκδόσεις· φιλοξενεί
    discrete εκδόσεις (as-enacted ή τελική τροποποιημένη), σε αντίθεση με επίσημες
    υπηρεσίες κωδικοποίησης (π.χ. legislation.gov.uk).
  - Τα κείμενα χαρακτηρίζονται ρητά ως ανεπίσημες αναπαραγωγές, όχι αυθεντικές νομικές
    αναφορές.
  - Η φιλοξενία νομικών κειμένων σε γενικού σκοπού wiki απαιτεί διαρκή (όχι εφάπαξ)
    προσπάθεια — proofreading, validation, indexing fixes αναφέρονται συχνά.
- **Wikisource:Versions** (secondary):
  - Επιτρέπονται απεριόριστες ξεχωριστές εκδόσεις/versions του ίδιου έργου· διαφορετικές
    εκδόσεις δεν συγχωνεύονται ποτέ στην ίδια σελίδα, παραμένουν ξεχωριστές σελίδες.
  - Απαγορεύεται η σύνθεση έργου από πολλαπλές πηγές· το περιεχόμενο κάθε έκδοσης πρέπει
    να ανάγεται σε μία συγκεκριμένη δημοσίευση (verifiability), όχι σε "καλύτερο"
    συγκερασμένο κείμενο.
  - Διαφορετικές εκδόσεις διαχωρίζονται με suffix στον τίτλο σελίδας (έκδοση, έτος,
    εκδότης — π.χ. "(1st edition)", "(1883)", "(Holt text)"), όχι μέσω revision history.
  - Προεπιλεγμένη editorial προτίμηση όταν υπάρχουν πολλαπλές εκδόσεις: η αρχαιότερη
    δημοσίευση, με τεκμηρίωση στη σελίδα talk για κάθε απόκλιση.
- **Meta-Wiki — WikiLaw (3) / Talk** (medium confidence, stalled πρόταση):
  - Πρόταση αφιερωμένου wiki για νομική έρευνα/νομοθεσία, σχεδιασμένο να ενσωματώνεται
    με (όχι να αντιγράφει) τα νομικά κείμενα του Wikisource.
  - "Η μορφοποίηση των νόμων αλλάζει με τον χρόνο και τη δικαιοδοσία" — αναφέρεται ως
    πρόκληση.
  - Αναφέρεται η γενικότερη πρόκληση να πεισθούν νομικοί επαγγελματίες να εμπιστευτούν
    πηγή "wiki" ως αυθεντική.
- **Data Foundation — Version Control for Law (US Congress)** (medium confidence):
  - Η διαχείριση εκδόσεων νομικού κειμένου διαφέρει θεμελιωδώς από version control
    λογισμικού: οι τροποποιήσεις εκφράζονται συνήθως ως εντολές ("strike", "insert",
    "repeal") που πρέπει να ερμηνευτούν και να εφαρμοστούν, όχι ως diffs του κειμένου.
  - Για πολλούς πρόσφατα τροποποιημένους νόμους δεν υπάρχει "official version" ούτε
    ακριβής τρόπος να δει κανείς το ιστορικό τροποποιήσεων.

## 6. Πηγές

- OASIS Akoma Ntoso v1.0 Part 1: XML Vocabulary — https://docs.oasis-open.org/legaldocml/akn-core/v1.0/os/part1-vocabulary/akn-core-v1.0-os-part1-vocabulary.html
- OASIS Akoma Ntoso Naming Convention v1.0 — https://docs.oasis-open.org/legaldocml/akn-nc/v1.0/akn-nc-v1.0.html
- Akoma Ntoso — Schema reference — http://akomantoso.info/?page_id=47
- UN AKN4UN guide (Metadata, Modifications) — https://unsceb-hlcm.github.io/part1/
- Legislation.gov.uk data model — https://legislation.github.io/data-documentation/model/overview.html
- Legislation.gov.uk API — https://legislation.github.io/data-documentation/api/overview.html
- National Archives — legislation.gov.uk Website Information Guide (PDF) — https://cdn.nationalarchives.gov.uk/documents/cas-82049-legislation-website-information-guide.pdf
- EUR-Lex — What is ELI? — https://eur-lex.europa.eu/eli-register/about.html
- EUR-Lex — ELI help page — https://eur-lex.europa.eu/content/help/eurlex-content/eli.html
- EUR-Lex — European Legislation Identifier summary — https://eur-lex.europa.eu/EN/legal-content/summary/european-legislation-identifier-eli.html
- ELI Technical Implementation Guide (PDF) — https://op.europa.eu/documents/2050822/2138819/ELI+-+A+Technical+Implementation+Guide.pdf/
- EU Vocabularies — ELI overview — https://op.europa.eu/en/web/eu-vocabularies/eli
- EUR-Lex CELLAR API developer guide — https://polzia.com/blog/eur-lex-cellar-api-developers-guide
- European Legislation Identifier — Wikipedia — https://en.wikipedia.org/wiki/European_Legislation_Identifier
- European Case Law Identifier — Wikipedia — https://en.wikipedia.org/wiki/European_Case_Law_Identifier
- EUR-Lex — Wikipedia — https://en.wikipedia.org/wiki/EUR-Lex
- search.et.gr — Εθνικό Τυπογραφείο, ΦΕΚ portal — https://search.et.gr/en/fek/
- Εφημερίδα της Κυβερνήσεως — Βικιπαίδεια — https://el.wikipedia.org/wiki/Εφημερίδα_της_Κυβερνήσεως
- Extension:FlaggedRevs — MediaWiki.org — https://www.mediawiki.org/wiki/Extension:FlaggedRevs
- Wikisource — Help:Official texts — https://en.wikisource.org/wiki/Help:Official_texts
- Wikisource — WikiProject UK Law — https://en.wikisource.org/wiki/Wikisource:WikiProject_UK_Law
- Wikisource — Versions — https://en.wikisource.org/wiki/Wikisource:Versions
- Meta-Wiki — WikiLaw (3) / Talk — https://meta.wikimedia.org/wiki/WikiLaw_(3), https://meta.wikimedia.org/wiki/Talk:WikiLaw_(3)
- Data Foundation — Version Control for Law (US Congress) — https://datafoundation.org/news/blogs/335/335-Version-Control-for-Law-Tracking-Changes-in-the-U.S.-Congress
- Springer — Legislative Change Management with Akoma-Ntoso — https://link.springer.com/chapter/10.1007/978-94-007-1887-6_7
- legisinfo.com — Mapping Amending Language to Akoma Ntoso Modifications — https://legisinfo.com/2022/01/26/understanding-akoma-ntoso-change-management/
- arXiv 2506.07853 — LRMoo-based event-centric legal norms model — https://arxiv.org/abs/2506.07853 (βλ. §1 για το τι πράγματι υποστηρίζει vs. τι αναιρέθηκε)
- Laws.Africa — Laws.Africa, AfricanLII and Kenya Law: faster, cheaper, better — https://laws.africa/2019/03/14/laws-dot-africa-africanlii-and-kenya-law-faster-cheaper-better.html
- Indigo Platform (Laws.Africa) — https://laws.africa/indigo/
- Indigo Platform docs — Principles — https://indigo.readthedocs.io/en/latest/guide/principles.html
- EUR-Lex — European Legislation Identifier (ELI) summary — https://eur-lex.europa.eu/EN/legal-content/summary/european-legislation-identifier-eli.html
- Council conclusions of 6 November 2017 on the European Legislation Identifier (2017/C 441/05) — https://eur-lex.europa.eu/legal-content/EN/TXT/PDF/?uri=CELEX:52017XG1222(02)
- Government Gazette (Greece) — Wikipedia — https://en.wikipedia.org/wiki/Government_Gazette_(Greece)
- search.et.gr — Αναζήτηση Νομοθεσίας — https://search.et.gr/en/search-legislation/
- Guide to Law Online: Greece — Legislative, Library of Congress *(fetch απέτυχε — HTTP 403· εύρημα βασισμένο μόνο σε search snippet)* — https://guides.loc.gov/law-greece/legislative
- PyEuroVoc / KEVLAR — εργαλεία αυτόματης EuroVoc ταξινόμησης — https://arxiv.org/pdf/2108.01139 , https://aclanthology.org/2024.clicit-1.9.pdf

