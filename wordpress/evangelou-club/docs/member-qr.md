# QR μέλους: QR του πελάτη και σάρωση στο ταμείο (STAGE-05C)

**Κατάσταση:** ανάπτυξη μόνο. ΔΕΝ είναι ενεργό πουθενά. Χωρίς τις σταθερές που ήδη υπάρχουν για το
Club (βλ. «Πώς ενεργοποιείται»), οι νέες διαδρομές **δεν υπάρχουν καν** και το plugin συμπεριφέρεται
ακριβώς όπως πριν.

## Για τον τεχνικό / τον ιδιοκτήτη (ελληνικά)

### Αποφάσεις ιδιοκτήτη (2026-11-04)

| | Απόφαση |
|---|---|
| **D6** διάρκεια / αλλαγή | «Ίδιος μέχρι να ζητηθεί νέος». Ο QR δεν λήγει. Αλλάζει μόνο όταν ο πελάτης πατήσει «νέο QR» ή όταν ο διαχειριστής ακυρώσει τον παλιό. Το κινητό τον κρατά. **Το «πατήσει «νέο QR»» καταργήθηκε (D21) και ο πελάτης δεν αλλάζει πια ποτέ τον QR (D22).** Η «καμία λήξη» ισχύει. |
| **D7** στιγμιότυπο οθόνης | «Ναι, το δεχόμαστε». Μια φωτογραφία οθόνης του QR δουλεύει. Ο ένας καφές ανά ημέρα Αθήνας περιορίζει κάθε απώλεια. |
| **D8** τι βλέπει το ταμείο | «Μικρό όνομα + κατάσταση»: «ενεργό», «όχι ενεργό» ή «ο καφές πάρθηκε σήμερα». Ποτέ επώνυμο, τηλέφωνο, e-mail ή άλλα στοιχεία. |
| **D9** λάθος σαρώσεις | Περίπου **10 αποτυχίες ανά 5 λεπτά, ανά tablet**. Ο μετρητής ζει σε έναν νέο μικρό πίνακα (μετάβαση 003). Εγκρίθηκε ο **κώδικας**. Η εφαρμογή του 003 εγκρίθηκε **μόνο στο staging** (D19, 2026-11-10)· στην παραγωγή χρειάζεται **ξεχωριστή** έγκριση. |
| **D9α** νέα σύνδεση tablet | «Όχι, μένει έτσι». Οι **επιτυχημένες** νέες συνδέσεις του tablet δεν έχουν όριο (το `EVC_Login_Throttle` μετρά μόνο λάθος κωδικούς). Κάθε νέα σύνδεση δίνει νέες ~10 προσπάθειες. **Αποδεκτός κίνδυνος**, χωρίς νέο κώδικα. |
| **D10** ακύρωση QR | «Όχι, μόνο ο διαχειριστής». Το tablet μόνο σαρώνει. |
| **D21** (2026-11-19) πάντα ορατός QR | Στην κάρτα μέλους ο QR φαίνεται **πάντα** όταν η συνδρομή είναι ενεργή· το κουμπί «Νέο QR» καταργείται. Το δεύτερο μισό της απόφασης (η εφαρμογή ζητούσε μόνη της νέο QR σε άλλη συσκευή και ακύρωνε τον παλιό) **αντικαταστάθηκε από το D22**. |
| **D23** (2026-11-19) καφέδες στην κάρτα | Η κάρτα μέλους δείχνει **πραγματικά** στοιχεία από τη βάση του Club: πόσους δωρεάν καφέδες πήρε το μέλος **αυτόν τον ημερολογιακό μήνα** (ώρα Ελλάδας) και αν πήρε ήδη τον **σημερινό**. Αν η βάση δεν απαντά, η κάρτα δείχνει «—» όπως πριν. |
| **D22** (2026-11-19) ίδιος QR παντού | «Όχι, να μην αλλάζει ποτέ». Ο QR του μέλους είναι **ο ίδιος σε κάθε συσκευή και κάθε πρόγραμμα περιήγησης**, και το άνοιγμα της κάρτας οπουδήποτε **δεν** τον ακυρώνει ποτέ. Στιγμιότυπο οθόνης από οποιαδήποτε συσκευή ισχύει. Νέος κωδικός βγαίνει **μόνο** όταν ο διαχειριστής ακυρώσει τον παλιό. Για να γίνεται αυτό, ο διακομιστής **παράγει** τον κωδικό από ένα νέο μυστικό (`EVC_CLUB_QR_SECRET`) και μπορεί να τον ξαναδώσει στο ίδιο μέλος. Χωρίς νέα μετάβαση βάσης. |

### Τι κάνει

1. **Ο πελάτης** (με αριθμό μέλους, δηλαδή με καταγεγραμμένη πληρωμή, STAGE-05B) ανοίγει την κάρτα του
   και βλέπει **τον** QR του. Από όποια συσκευή κι αν ανοίξει την κάρτα, βλέπει **τον ίδιο** QR (D22).
   Το άνοιγμα της κάρτας δεν αλλάζει και δεν ακυρώνει ποτέ τον QR.
2. Στη βάση **δεν** γράφεται ποτέ ο ίδιος ο QR, μόνο ένα «αποτύπωμά» του. Ο διακομιστής τον ξαναφτιάχνει
   κάθε φορά από το μυστικό `EVC_CLUB_QR_SECRET`, μόνο για το ίδιο το συνδεδεμένο μέλος.
3. **Το ταμείο** σαρώνει τον QR και βλέπει το μικρό όνομα και την κατάσταση. Ο QR **δεν δίνει μόνος του
   καφέ**: όταν ο υπάλληλος πατήσει «Δωρεάν καφές», ο έλεγχος γίνεται ξανά από την αρχή (πληρωμένη και
   καταγεγραμμένη συνδρομή, ένας καφές ανά ημέρα Αθήνας, ακύρωση / επιστροφή χρημάτων = παγωμένη κάρτα).
   Παγωμένη ή ληγμένη κάρτα φαίνεται «όχι ενεργή».
4. Ένας άγνωστος, αντικατεστημένος ή ακυρωμένος QR δίνει **την ίδια** απάντηση «δεν βρέθηκε μέλος». Το
   ταμείο δεν μαθαίνει ποτέ αν ένας κωδικός υπήρξε κάποτε.
5. Μετά από **10 αποτυχημένες σαρώσεις σε 5 λεπτά**, **αυτό** το tablet περιμένει ως το τέλος του
   5λεπτου. Τα άλλα tablets δουλεύουν κανονικά.
6. **Χαμένο κινητό:** ο πελάτης συνδέεται από το νέο κινητό και βλέπει τον ίδιο QR. Αν θέλει να μη
   δουλεύει πια ο QR του χαμένου κινητού, το ζητά από το κατάστημα: **μόνο ο διαχειριστής** του
   WordPress ακυρώνει τον QR ενός πελάτη (με τον αριθμό χρήστη WordPress), και τότε το μέλος παίρνει νέο
   κωδικό στο επόμενο άνοιγμα της κάρτας. Ο κοινός λογαριασμός του ταμείου **δεν** μπορεί.

### Καφέδες στην κάρτα (D23)

- Η κάρτα ρωτά τη βάση του Club (μόνο ανάγνωση) για **τα δικά της** στοιχεία του μέλους: αν δόθηκε ο
  σημερινός καφές (ημέρα Αθήνας, ίδια με τον κανόνα «ένας καφές την ημέρα» του ταμείου) και πόσοι καφέδες
  δόθηκαν αυτόν τον μήνα (μήνας Αθήνας). Ποτέ ονόματα υπαλλήλων, ποτέ στοιχεία άλλου μέλους.
- Ο τίτλος της κάρτας γράφει «Ο σημερινός καφές σας περιμένει» ή «Τον απολαύσατε σήμερα» και η γραμμή
  «Καφέδες αυτόν τον μήνα» τον αριθμό. Αν η βάση δεν απαντά, η κάρτα μένει όπως πριν: «—» και «Η συνδρομή
  σας είναι ενεργή».
- Τα στοιχεία ξαναζητούνται όταν η κάρτα ξαναφαίνεται στην οθόνη (το πολύ μία φορά ανά 30 δευτερόλεπτα,
  και το πολύ 3 φορές το λεπτό). Το αναλυτικό ιστορικό καφέδων **δεν** εμφανίζεται ακόμη.
- Δεν χρειάζεται νέα μετάβαση βάσης ούτε νέο μυστικό. Ο χρήστης της βάσης του Club χρειάζεται `SELECT`
  στον πίνακα `evc_redemptions` (τον έχει ήδη, γιατί το ταμείο γράφει εκεί).

### Οι οθόνες (STAGE-05D)

- **Tablet του ταμείου.** Το κουμπί «Σάρωση QR μέλους» εμφανίζεται **μόνο** όταν ο διακομιστής λέει ότι
  η σάρωση είναι πραγματικά έτοιμη, δηλαδή ότι ισχύουν όλα όσα γράφει το «Πώς ενεργοποιείται» παρακάτω,
  μαζί με τη μετάβαση 003. Μέχρι τότε η οθόνη είναι όπως πριν, με όλες τις λειτουργίες απενεργοποιημένες.
  1. Ο υπάλληλος σαρώνει τον κωδικό, με την κάμερα του tablet ή με σαρωτή χειρός.
  2. Βλέπει **μόνο** το μικρό όνομα και την κατάσταση: «Ενεργό μέλος · δικαιούται καφέ σήμερα», «Ο
     σημερινός καφές έχει ήδη δοθεί» ή «Η συνδρομή δεν είναι ενεργή».
  3. Για ενεργό μέλος διαλέγει τον καφέ από τη λίστα v1, όπου υπάρχει και το «Άλλος καφές».
  4. Πατά «Καταχώρηση καφέ».

  Αν δεν επιβεβαιωθεί η καταχώρηση, το «Δοκιμή ξανά» στέλνει **το ίδιο** αίτημα, οπότε δεν μπορεί να
  γραφτεί δεύτερος καφές. Το tablet **δεν** ακυρώνει ποτέ QR.
- **Κάμερα και σαρωτής (απόφαση ιδιοκτήτη 2026-11-04: «Δεν ξέρω ακόμα, να δουλεύει σε όλες»).**
  Λειτουργεί σε iPad, σε tablet Android και με σαρωτή χειρός USB/Bluetooth:
  - Στον Chrome (Android) η κάμερα χρησιμοποιεί την ανάγνωση QR του ίδιου του προγράμματος περιήγησης.
  - Στο Safari (iPad) φορτώνεται, μόνο όταν ανοίξει η κάμερα, ένα μικρό πρόσθετο αρχείο ανάγνωσης QR από
    το ίδιο το plugin. Η εικόνα της κάμερας διαβάζεται μόνο στη μνήμη και δεν φεύγει από το tablet.
  - Ο σαρωτής χειρός δουλεύει και με ελληνικό πληκτρολόγιο ή με Caps Lock: τα «εωψ_…» διορθώνονται
    σε «evc_…».
  - Ένας κωδικός που δεν είναι κωδικός του Club απορρίπτεται στο tablet και δεν στέλνεται. Έτσι δεν
    μετράει στις 10 αποτυχίες ανά 5 λεπτά.
  - Μετά από πολλές αποτυχίες η οθόνη δείχνει αντίστροφη μέτρηση («Δοκιμάστε ξανά σε 4:59») και
    ξανανοίγει μόνη της.
  - Η κάμερα κλείνει πάντα όταν κλειδώσει η οθόνη, ακόμη κι αν εκείνη τη στιγμή περίμενε ακόμη την άδεια.
- **Κινητό του πελάτη.**
  - Η κάρτα δείχνει τον πραγματικό QR του μέλους.
  - **D21 + D22 (2026-11-19):** ο QR φαίνεται πάντα· δεν υπάρχει κουμπί «Νέο QR» ούτε ερώτηση. Όσο
    έρχεται ο κωδικός, η κάρτα γράφει «Φόρτωση του κωδικού σας…». Κάτω από τον QR: «Ο κωδικός σας μένει
    ίδιος σε όλες τις συσκευές σας και δεν αλλάζει όταν ανοίγετε την κάρτα.» και «Στιγμιότυπο οθόνης
    ισχύει κανονικά στο ταμείο, από όποια συσκευή κι αν το δείξετε.»
  - Σε μια συσκευή που δεν έχει ακόμη τον QR (άλλο κινητό, ιδιωτική περιήγηση), η εφαρμογή τον ζητά από
    τον διακομιστή και παίρνει **τον ίδιο** κωδικό. Δεν ζητά ποτέ αντικατάσταση. Όταν η καρτέλα ξαναγίνει
    ορατή, ρωτά τον διακομιστή (το πολύ κάθε 30 δευτερόλεπτα) αν ο QR που δείχνει ισχύει ακόμη.
  - **Αδύναμο Wi-Fi (round 7):** αν ο έλεγχος αυτός αποτύχει, ο QR **μένει στην οθόνη** και ο έλεγχος
    ξαναγίνεται αθόρυβα αργότερα. Αν η κάρτα ανοίξει χωρίς να απαντά ο διακομιστής, δείχνει τον QR που
    έχει αποθηκευμένο η συσκευή για αυτό το μέλος, με τη σημείωση «Δεν μπορέσαμε να ελέγξουμε τον κωδικό
    αυτή τη στιγμή…», και τον ελέγχει μόλις γίνει δυνατό (και όταν επανέλθει η σύνδεση).
  - Αν αποτύχει και δεν υπάρχει αποθηκευμένος QR (δίκτυο, διακομιστής), γράφει σύντομο μήνυμα,
    ξαναδοκιμάζει μόνη της σε 5, 15, 30 και μετά κάθε 60 δευτερόλεπτα, και δείχνει έναν μικρό σύνδεσμο
    «Δοκιμάστε ξανά» **μόνο** τότε. Σε κάθε λεπτό στέλνει το πολύ 8 αιτήματα, από τα οποία το πολύ 3 POST.
  - Όταν το μέλος φύγει από την κάρτα (άλλη οθόνη της εφαρμογής), σταματούν όλες οι αυτόματες
    προσπάθειες· ξαναρχίζουν όταν επιστρέψει στην κάρτα.
  - Ένα στιγμιότυπο οθόνης του QR δουλεύει από οποιαδήποτε συσκευή (D7, D22· ο διακομιστής δεν βάζει
    λήξη, D6), ώσπου ο διαχειριστής να ακυρώσει τον QR.
  - **Το κινητό θυμάται τον QR** (απόφαση ιδιοκτήτη 2026-11-04: «Ναι, να τον θυμάται»). Τον κρατά στην
    αποθήκευση του προγράμματος περιήγησης, χωρίς e-mail ή ημερομηνία σε αναγνώσιμη μορφή, και τον
    δείχνει **μόνο** αν ο διακομιστής λέει ότι ισχύει ακόμη ο ίδιος QR.
  - Ο QR σβήνεται από το κινητό όταν:
    - ο πελάτης αποσυνδέεται ή η σύνδεση έχει λήξει·
    - ο λογαριασμός δεν είναι (πια) μέλος ή η κάρτα είναι ανενεργή·
    - η λειτουργία QR είναι κλειστή·
    - ο διακομιστής έχει άλλον QR, για παράδειγμα αν ο διαχειριστής ακύρωσε τον παλιό.
  - Αν το κινητό χάσει τον αποθηκευμένο QR, η εφαρμογή τον ξαναζητά και παίρνει **τον ίδιο** (D22).

### Πώς ενεργοποιείται (ΜΟΝΟ με έγκριση του ιδιοκτήτη, πρώτα στο staging)

- **QR πελάτη:** `EVC_CLUB_CUSTOMER_ENABLED = true` **και** `EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1'`
  (+ `EVC_CLUB_DB_*`) **και** (D22) ένα νέο μυστικό στο `wp-config.php`:

      define('EVC_CLUB_QR_SECRET', '...τουλάχιστον 32 τυχαίοι χαρακτήρες, ΔΙΑΦΟΡΕΤΙΚΟ από το EVC_CLUB_LOOKUP_KEY...');

  Το παράδειγμα αυτό **δεν** γίνεται δεκτό, ούτε ένας ίδιος χαρακτήρας ξανά και ξανά: χρειάζεται τυχαίο
  κείμενο. Φτιάξτε το έτσι (64 χαρακτήρες):

      php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

  ή, σε Windows PowerShell 5:

      $b=New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); ($b|%{$_.ToString('x2')}) -join ''

  Η βάση του Club πρέπει να έχει τουλάχιστον τις μεταβάσεις 001 + 002 (**καμία νέα μετάβαση** για το D22).
  Χωρίς έγκυρο `EVC_CLUB_QR_SECRET` η κάρτα δεν δείχνει QR (η υπηρεσία απαντά «μη διαθέσιμη»,
  `qr_secret_missing`). Η σελίδα «Ετοιμότητα» δείχνει τη γραμμή «Μυστικό κλειδί του κωδικού QR μέλους».
  **Προσοχή:** αν αλλάξει αυτό το μυστικό, **όλοι** οι QR των μελών αλλάζουν μία φορά στο επόμενο άνοιγμα
  της κάρτας τους (και τα παλιά στιγμιότυπα παύουν να ισχύουν). Μην το αλλάζετε χωρίς λόγο.
- **Σάρωση στο ταμείο:** ό,τι ήδη χρειάζεται ο δωρεάν καφές (STAGE-05A: `EVC_CLUB_STAFF_ENABLED`,
  `EVC_CLUB_REDEMPTION_SOURCE = 'club_db_v1'`, πηγή συνδρομών, `EVC_CLUB_PAYMENT_PROVENANCE`, `EVC_CLUB_DB_*`)
  **και** τη μετάβαση **003** **και** ένα νέο μυστικό στο `wp-config.php`:

      define('EVC_CLUB_LOOKUP_KEY', '...τουλάχιστον 32 τυχαίοι χαρακτήρες...');

  Το βάζει **ο τεχνικός**, ένα διαφορετικό για κάθε περιβάλλον (staging ≠ παραγωγή). Δεν μπαίνει ποτέ
  σε git, e-mail ή chat. Π.χ. `php -r "echo bin2hex(random_bytes(32));"` (64 χαρακτήρες). Αν λείπει
  ή είναι μικρότερο από 32 bytes, η σάρωση απαντά «η υπηρεσία δεν είναι διαθέσιμη».

### Δικαιώματα στη βάση του Club (όταν ενεργοποιηθεί)

| Πίνακας | Δικαιώματα |
|---|---|
| `evc_members` | `SELECT` |
| `evc_qr_tokens` | `SELECT`, `INSERT`, `UPDATE (status, revoked_at_utc)`. Ποτέ `DELETE`. |
| `evc_redemptions` | `SELECT` (D23: οι καφέδες της κάρτας· το ταμείο έχει ήδη και `INSERT`) |

**Ποτέ μη σβήνετε γραμμές του `evc_qr_tokens`**, ούτε απευθείας στη βάση. Ο κωδικός κάθε μέλους
βγαίνει από το πόσες γραμμές έχει εκεί. Αν σβηστούν γραμμές, ένας παλιός ακυρωμένος κωδικός μπορεί να
ξαναγίνει έγκυρος, ή το μέλος να μείνει χωρίς QR. Η εφαρμογή αντέχει έως 16 σβησμένες γραμμές ανά μέλος·
πιο πέρα γράφει στο αρχείο σφαλμάτων `qr_generation_stuck` (δείτε παρακάτω).
| `evc_lookup_failures` (003) | `SELECT`, `INSERT`, `UPDATE`, `DELETE` (μόνο για καθαρισμό γραμμών πάνω από μίας ημέρας) |

### Τι βλέπει ο τεχνικός στο αρχείο σφαλμάτων

Μία γραμμή χωρίς κωδικούς, ονόματα ή QR, π.χ.

    Evangelou Club: staff QR unavailable (lookup_key_missing).
    Evangelou Club: customer QR unavailable (club_db_schema_too_old).

| Αιτία | Τι κάνετε |
|---|---|
| `lookup_key_missing` | Λείπει ή είναι μικρό το `EVC_CLUB_LOOKUP_KEY`. |
| `qr_secret_missing` | Λείπει, είναι μικρό ή είναι ίδιο με το `EVC_CLUB_LOOKUP_KEY` το `EVC_CLUB_QR_SECRET` (D22). |
| `club_db_schema_too_old` | Ταμείο: δεν εφαρμόστηκε το 003. Πελάτης: λείπουν 001 + 002. |
| `qr_generation_stuck` | Σβήστηκαν γραμμές του `evc_qr_tokens` (πάνω από 16 για ένα μέλος) και το μέλος δεν μπορεί να πάρει QR. Μην προσθέσετε ούτε σβήσετε τίποτα με το χέρι: ενημερώστε τον προγραμματιστή. |
| `coffees_failed` | Η κάρτα δεν διάβασε τους καφέδες του μέλους (D23)· δείχνει «—». Συνήθως σημαίνει ότι λείπει το `SELECT` στο `evc_redemptions`. |
| `payment_provenance_not_recorded` | Λείπει ή είναι λάθος το `EVC_CLUB_PAYMENT_PROVENANCE`. |
| άλλες (`staff_flag_off`, `redemption_source_off`, `commerce_source_not_configured`, `club_db_not_configured`, `club_db_unavailable`) | Όπως στο `docs/staff-redemption.md`. |

### Ακύρωση QR: μόνο ο διαχειριστής (απόφαση ιδιοκτήτη, 2026-11-04)

Το ερώτημα «να ακυρώνει QR και ο κοινός λογαριασμός του ταμείου;» έκλεισε: «Όχι, μόνο ο
διαχειριστής». Το tablet μόνο σαρώνει. Η ακύρωση γίνεται μόνο από τον διαχειριστή
(`evc_manage_club`), όπως στον εγκεκριμένο σχεδιασμό UI-04C, με την υπάρχουσα διαδρομή
`POST evangelou-club/v1/admin/members/qr/revoke` (D22: δεν προστέθηκε νέα οθόνη). Μετά την
ακύρωση το μέλος παίρνει **νέο** κωδικό στο επόμενο άνοιγμα της κάρτας του, και αυτός μένει πια ίδιος
σε όλες τις συσκευές.

---

## Developer notes (English)

### Pieces and gates

| Piece | File | Constructed (packager rule set `qrProblemsFor`) |
|---|---|---|
| `EVC_Sql_Qr_Token_Store` (UI-04C `EVC_Qr_Token_Store`, 001) | `includes/class-evc-sql-qr-token-store.php` | only `EVC_Club_Qr_Services::store()` |
| `EVC_Sql_Lookup_Rate_Limiter` (UI-04C `EVC_Lookup_Rate_Limiter`, 003) | `includes/class-evc-sql-lookup-rate-limiter.php` | only `EVC_Club_Qr_Services::lookup_service()` |
| `EVC_Club_Qr_Services` + `EVC_Wp_Club_Staff_Authorizer`, `EVC_No_Member_Phone_Directory`, `EVC_First_Issue_Only_Qr_Store` | `includes/class-evc-club-qr-services.php` | the one site for `EVC_Qr_Token_Service`, `EVC_Member_Lookup_Service`, `EVC_Lookup_Key_Ring`, `EVC_Lookup_Context_Policy` |
| `EVC_Customer_Qr_Backend` + `EVC_Rest_Me_Qr_Controller` | `includes/customer/` | only `EVC_Customer_Plugin::register_routes()` inside `if (EVC_Customer_Qr_Backend::provenance_recorded()) { … }` (after the customer flag gate) |
| `EVC_Club_Lookup_Backend` + `EVC_Rest_Lookup_Controller` | `includes/staff/` | only `EVC_Staff_Plugin::register_routes()` inside `if (EVC_Club_Redemption_Feature::enabled()) { … }` (after the staff gate) |

Each backend re-checks everything per request before reading, and opens no Club DB connection before
its last config check:
- **Customer:** customer flag `true` → provenance `'club_db_v1'` → `EVC_Db_Config` → `EVC_CLUB_QR_SECRET`
  (D22: `EVC_Qr_Token::is_derivation_secret`, >= 32 bytes and not `EVC_CLUB_LOOKUP_KEY`) → connect → schema `>= 2`.
- **Staff:** staff flag → `EVC_CLUB_REDEMPTION_SOURCE` → commerce config → provenance → `EVC_Db_Config`
  → `EVC_CLUB_LOOKUP_KEY` `>= 32` bytes → connect → schema `>= 3`.

Any failure → 503. Both connections run `SET SESSION innodb_lock_wait_timeout = 5`
(`EVC_Club_Qr_Services::prepare_connection`), like the 05A / 05B paths.

### Packager guard (`app/scripts/php-guards.mjs`, `qrProblemsFor` + `qrSelfTest` with its fixtures)

The guard replaces the blanket string bans on:
- `new EVC_Qr_Token_Service`, `new EVC_Member_Lookup_Service`, `new EVC_Lookup_Key_Ring`;
- `implements EVC_Qr_Token_Store | EVC_Member_Phone_Directory | EVC_Lookup_Rate_Limiter | EVC_Club_Staff_Authorizer`.

It enforces these rules:
- **One site per class:** each class is constructed only at its single site, at most once there.
- **Exact wiring:** the two backends are wired by the exact standalone statement
  `if (<gate>) { (new <Controller>(new <Backend>())) ->register_routes(); }`, with no test-seam
  arguments.
- **Exact customer gate:** the body of `provenance_recorded()` must be exactly
  `return defined('EVC_CLUB_PAYMENT_PROVENANCE') && constant('EVC_CLUB_PAYMENT_PROVENANCE') === self::PROVENANCE;`
  with `PROVENANCE = 'club_db_v1'`.
- **Implementations:** each interface is implemented only by its own `class` (no enum, trait,
  interface or anonymous class), and no shipped interface extends one.
- **Names:** each QR class name, as an identifier or inside a string, appears only in its
  allowlisted files. This includes the reviewed `EVC_Qr_Token_Service`, `EVC_Member_Lookup_Service` and
  `EVC_Lookup_Key_Ring`, which only `class-evc-member-lookup.php` and the services file may name. So
  `X::class` followed by `new $c` cannot reach them from any other shipped file.
- **Name fragments:** no other shipped string may contain a QR class-name fragment
  (`qr_token_store`, `member_lookup_service`, …).
- **No dynamic construction in the QR files:** `new $x`, `new (…)`, `new self|static|parent|class`,
  `class_alias`, `Reflection*`, `newInstance*` and `unserialize` are refused. `new $x`, `new (…)` and
  `new self|static|parent` are also refused in the staff and customer plugin files, which may name
  their backend (review: `EVC_Customer_Qr_Backend::class` + `new $c`).

Not covered statically: a class name assembled at run time, outside the QR files, from pieces that are
not QR name fragments. The `new EVC_Signed_Member_Context_Verifier` ban and every v2 ban are unchanged.

### Token store (`evc_qr_tokens`, 001 unchanged)

**Storage.** Digests only. The store refuses a plaintext token passed as a digest (mutant QS10) and
never returns a digest from a lookup. `find_member_by_wp_user()` was added for the backends; it is not
part of the UI-04C interface.

**rotate() and revoke().** Each is ONE READ COMMITTED transaction:
1. `SELECT token_hash … WHERE active_member_id = ? FOR UPDATE` on the one-active key.
2. A `hash_equals` compare-and-set against the expected digest (none vs. some counts as a mismatch).
3. A guarded UPDATE: `… WHERE active_member_id = ? AND token_hash = ? AND status = 'active'`,
   which must affect exactly 1 row.
4. rotate() only: the INSERT of the new row.

**Failure outcomes.**
- A duplicate key rolls back, including the revocation.
- A deadlock or lock-wait timeout → `EVC_Qr_Rotation_Conflict`.
- A lost COMMIT → `EVC_Db_Commit_Unknown_Exception`.

**Locks.** The store never takes an explicit lock on `evc_members`. The FK check on the INSERT does
take a **shared** lock on the member row for the rest of the transaction; only a concurrent writer of
that member row could wait on it, and nothing shipped writes member rows on this path. The 5 s session
lock-wait bounds any wait.

**Defence in depth.**
- For **rotate()**, any one of these stops a late writer on its own: the row lock, the digest compare,
  the guarded UPDATE and the unique key.
- For **revoke()**, which has no INSERT so the unique key does not apply, the row lock, the digest
  compare and the guarded UPDATE each stop it.
- Removing only the lock, or only the row-count check, is therefore not observable (equivalent mutants,
  not listed).
- Proven with real separate processes in CI:
  - `test_simultaneous_writers_…` (first issuance and rotation);
  - `test_simultaneous_revoke_and_rotate_…` (exactly one winner);
  - `test_a_writer_blocked_by_an_uncommitted_replacement_…`: another connection really holds the row
    and has replaced it, uncommitted. A writer in a separate process announces that its next statement
    is the store call and is still blocked 1.5 s later. After the commit it re-reads and loses.
    `INNODB_TRX` is not the proof: on the CI MySQL 8 containers it listed only the holder while the
    writer was blocked.

### Customer route (`evangelou-club-customer/v1/me/qr`)

**GET** → `{"result":"ok","qr":{"state":…}}`. The possible states:
- `active`, with `issued_at` in Athens RFC 3339;
- `none`;
- `not_member`;
- `member_disabled`.

GET never returns a token.

**POST**, with body `{}` (or `{"replace": bool}` from older cached apps: validated, then **ignored**, D22;
anything else → 400):

| Answer | Meaning |
|---|---|
| 200 with `token` + `issued_at` | The ONLY reply that carries the plaintext: the member's stable token (D22), the same on every call and every device; `issued_at` is the stored row's. |
| 409 `concurrent_change` | Another device of the same member stored the token at the same moment, twice in a row; ask again. |
| 404 `not_a_member` | No member row. |
| 403 `member_disabled` | The member is disabled. |
| 503 | Unavailable: a gate failed (incl. `qr_secret_missing`) or an unknown COMMIT outcome (asking again is safe: the next call finds the stored token or stores it). |

`409 qr_exists` is no longer sent (the client still accepts it from an older server and reads `GET` again).

**D22: the stable token (owner decision 2026-11-19, «Όχι, να μην αλλάζει ποτέ»).**
- `token = "evc_" + first 48 hex digits of HMAC-SHA256(EVC_CLUB_QR_SECRET, "evc-club-member-qr|v1|" + member_public_id + "|" + generation)`
  (`EVC_Qr_Token::derive`). Same format as before, so the till lookup by SHA-256 digest is unchanged.
- **Generation** = the number of the member's rows in `evc_qr_tokens` (`EVC_Sql_Qr_Token_Store::generation`).
  Rows are only added (`rotate()` inserts one; nothing deletes), so an active row is always the newest one
  and its generation is that count. **No schema change, no migration 004.**
- `issue()`: if the active row's digest equals the digest of `derive(generation)`, that token is returned and
  nothing is written. Otherwise (no active row: first QR or after the administrator's revocation; or an
  active row that is not the stable token) the token of `generation + 1` is stored by the existing
  compare-and-set `rotate(expected = what was read)`. A lost race with another device of the same member
  (rotation conflict or duplicate key) is read again once (`STABLE_TRIES = 2`).
- **Migration of tokens issued before D22 (staging):** an active random token is not the stable one, so the
  first `POST` after the upgrade replaces it **exactly once** with the stable token of the next generation;
  from then on every device gets that same token. Screenshots of the old random token stop working at that
  moment (once). The same happens once for every member if `EVC_CLUB_QR_SECRET` is changed.
- **Revocation** stays administrator-only (`revoke_for_wp_user`, `evc_manage_club`, the existing REST route;
  no new UI). It revokes the active row; the next `POST` stores generation + 1, a new code. A customer
  request can never revoke or change the QR.
- **Why derivation and not encrypted storage of the token:** encryption would need a new column (migration
  004 on every Club DB), a key anyway, an authenticated cipher and its nonce handling, and a decrypt path that
  can fail per row; a leaked key gives the same as a leaked derivation secret. Derivation needs no schema
  change, keeps the database at digests only, and has one pure, tested function. Rotating the secret is a
  deliberate "new code for everyone" switch.
- **Fail closed:** without a valid secret (missing, < 32 bytes, or equal to `EVC_CLUB_LOOKUP_KEY` so one leak
  never gives both) GET and POST answer 503 before any Club DB connection (`REASON_QR_SECRET`). Readiness row
  `qr_secret` («Μυστικό κλειδί του κωδικού QR μέλους (EVC_CLUB_QR_SECRET)»), value and length never shown.
- **Never logged, never shipped:** packager guard `qrSecretProblemsFor` (in `qrProblemsFor`): no shipped file
  may `define()` (or declare a constant named) `EVC_CLUB_QR_SECRET` / `EVC_CLUB_LOOKUP_KEY` /
  `EVC_CLUB_EVIDENCE_KEY`; `includes/class-evc-qr-token.php` may call no output / log / storage function at
  all; the customer backend may not pass a `$secret` / `$token` / `->secret` to one, nor to a dynamic call
  (its injected log callable). Self-tests in `qrSelfTest`.
- Tests: `CustomerQrTest` (same token on several "devices", `replace` ignored, revocation → new token,
  pre-D22 token replaced once, no other member's token, fail closed), `ReadinessTest`, commerce
  `CustomerQrCommerceTest` / `ReadinessCommerceTest` (CI). Mutants QC1–QC2, QD1–QD5, AT35.

**STAGE-08 round 8 (review of round 7).**
- **LOW-1:** `CustomerQrTest::test_round8_a_concurrent_first_issue_by_another_device_gives_this_device_the_same_token`
  makes the race deterministic: the test connection (`EVC_Faulty_Pdo::$before_prepare`) runs
  `tests/bin/qr-token-worker.php` (its own process and connection) just before this request's rotation locks
  the row; the worker stores the same generation-1 token and commits; this request's compare-and-set loses
  and its second try returns the same token (kills `STABLE_TRIES = 1`, mutant QC8).
  `…_an_active_row_that_is_not_the_members_own_is_never_handed_out` moves the active row to another member
  between the read and the row check (kills dropping the `member_id` check, QC9).
- **LOW-2 (no stored generation exists, no migration):** the generation is the row count, so
  **`evc_qr_tokens` rows must never be deleted**. If some are: `issue()` matches the active row against
  `derive(g)` for `g = count … count + GENERATION_PROBE` (16), so the stable token does not change; and the
  next generation skips every `g` whose digest is already stored as a **revoked** row, so a revoked code is
  never issued while its row exists (`next_generation()`; an ACTIVE row of that digest is a concurrent
  device's and is left to the compare-and-set). More than 16 used generations in a row = the stuck state:
  503 and the log reason `qr_generation_stuck` (`REASON_GENERATION`), never an endless 409. A revoked code
  whose row was deleted cannot be told apart and can come back: hence the rule. Readiness does not scan for
  it (it would need the QR secret and one derivation per member); the log line is the detection.
  Mutants QG1–QG3.
- **NIT-1:** `EVC_Qr_Token::is_derivation_secret()` also refuses a value containing `...` (the docs
  placeholder) or with fewer than `MIN_DISTINCT_BYTES` (8) different bytes (e.g. 32 × `k`); readiness row
  `qr_secret` follows (same function). Mutants QD6–QD7.
- **NIT-2:** `status()` clears `$this->secret` like `issue()` (and `coffees()` never sets it). Mutant QC10.

### Member card coffee figures (`GET evangelou-club-customer/v1/me/coffees`, owner decision D23)

- Registered by `EVC_Rest_Me_Qr_Controller::register_routes()` next to `/me/qr`, so it exists only with the
  same gates (customer flag + `EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1'`) and uses the same backend and
  per-request connection (`EVC_Customer_Qr_Backend::coffees()`). It does **not** need `EVC_CLUB_QR_SECRET`.
- `200 {"result":"ok","coffees":{"state":"member","today":"YYYY-MM-DD","taken_today":bool,"this_month":int}}`;
  `200 {"result":"ok","coffees":{"state":"not_member"|"member_disabled"}}`; `503 {"result":"unavailable"}`
  (any gate, connection, schema or read failure, log reason `coffees_failed` for a failed read). GET only;
  customer accounts only; always `get_current_user_id()`; no-store like every route of the namespace.
- One read-only SELECT (`EVC_Redemption_Store::member_coffee_counts`): the member's `free_coffee` rows by
  `business_date`, the Europe/Athens day the redeem engine writes (`EVC_Clock::business_date` of the
  redemption instant); `today` = the Athens day of the backend clock, the month = `Y-m-01 … Y-m-t` of it.
  The ledger has no reversal or void (no status column; the runtime user has no DELETE), so every row counts,
  as the engine's one-per-day key counts it. Other benefit types are not counted. No staff id, coffee code or
  time leaves the server (no history list: the approved screens' history needs the coffee, so it stays
  «not yet available»).
- Client: `app/src/customer/coffeesApi.ts` (strict parse: exact keys, a real date, `0 ≤ this_month ≤ 31`,
  taken today ⇒ at least 1), `memberCoffees.ts` (asks on open; wake on visibilitychange / focus / online and
  when the card is opened, at most every 30 s after an answer and 5 s after a failure, the QR flow's
  constants; hard cap 3 per rolling minute; 404, 401 and 403 = never asked again in the page; `not_member` /
  `member_disabled` are answers (30 s throttle, no 5 s re-ask); anything else → unknown), `liveView.ts`
  (figures used only when their `today` is the card's Athens day, else «—»; `historyData: "unavailable"`).
- Tests: `CustomerQrTest::test_d23_*` (month and Athens-midnight boundaries, another member's rows, other
  benefit type, every row counts, read only, not a member / disabled / signed out / staff, no secret needed,
  fail soft), `app/tests/club/memberCoffees.test.ts`. Mutants CF1–CF8 (WordPress) and D23-1… (frontend).

**Rules.**
- The member is always `get_current_user_id()`, and the member row is read by that WordPress user id, so
  the token derived is always the caller's own.
- Customer accounts only (`EVC_Customer_Auth`).
- Responses are no-store.

**The customer app (STAGE-05D).** The QR is drawn by `app/src/customer/qrEncode.ts`, a small encoder
with no dependency: byte mode, level M, versions 1–10, and a Club token is version 4.
- **Encoder tests** (`tests/club/qrEncode.test.ts`):
  - every output is decoded by an independent decoder (jsQR);
  - round 2: the output is **bit-exact** against `fixtures-qr-reference.json`, made offline with
    python-qrcode 7.4.2 (generator `gen-qr-reference.py`), for 4 tokens × every forced mask plus the
    automatic choice.
  - segno 1.6.6 was tried first and is not usable as a reference: when the data ends on a byte boundary it
    adds a whole 0x00 codeword before the pad bytes.
- **Client and flow.**
  - The client is `qrApi.ts`. Its replies are strict, and only a POST 200 carries the token.
  - The flow is `memberQr.ts` (`MemberQrFlow`) and the screen is `MemberQrPanel.tsx`, which has two fixed
    live regions (the confirmation dialog of the old «Νέο QR» is gone since D21).
  - D22: `CustomerQrApi.issue()` takes no argument and always sends `{}`; the client cannot ask for a
    replacement (mutants Q7, QR22-1).
  - The client and `/me` share one in-memory nonce (`createNonceBox`).
- **Where the plaintext lives** (round 2, owner decision 2026-11-04 «Ναι, να τον θυμάται»): in this page's
  memory AND on the phone, in `qrStore.ts`, the ONLY customer file allowed to touch browser storage.
  - **Store.** `localStorage`: the owner allowed it "if simpler" than IndexedDB. It is synchronous, needs
    no dependency, can be tested in jsdom, and is equally readable by page script.
  - **Key and value.** The key is `evc-club-qr:v1:` + SHA-256("evc-club-qr|" + e-mail + "|" + issued_at).
    The value is `{"v":1,"a": SHA-256(account), "t": token}`. No e-mail or date is stored in the clear.
  - **Shown** only when `GET /me/qr` reports exactly that `issued_at`. Any other record of the account is
    deleted on that read.
  - **Deleted on:**
    - sign-out (before WordPress signs out);
    - `signed_out` (everything, the in-memory copy too);
    - `not_member`, `member_disabled`, `forbidden`;
    - route off (everything);
    - `none`;
    - before every POST (the server answers with the stable token).
  - **Kept** on `unavailable` / error (a passing failure). Round 7: then a normal open shows it as
    `unchecked` (`peek()`: the account's one record whatever its `issued_at`, read only, nothing deleted).
  - **Fallback.** Without WebCrypto or a working `localStorage` (private mode, blocked, full), the QR stays
    in memory only.
  - D22: the server gives the member the same token again on request, so a lost record costs nothing.
  - **Shared phone and other tabs (round 4).**
    - The phone keeps at most ONE QR. A read for an account (the sign-in) deletes every other record,
      whatever its account, and a write replaces every record.
    - A sign-out or replace in another tab of the same browser fires a `storage` event that removes our
      key. This tab then drops its in-memory copy at once and asks the server again
      (`onExternalChange`).
    - The memory layer has its own epoch, so a read still in flight at sign-out never puts the token back
      into memory (the reviewer's `race2`).
  - **Sign-out race (round 3, review MAJOR 1).** A write computes two SHA-256 digests before `setItem`.
    Every `forget()` / `forgetAll()` bumps an epoch the moment it is called, and a write re-checks that
    epoch after its last await, in the same synchronous step as `setItem`. So no record can land after
    sign-out has started, and logout awaits the wipe before WordPress signs out. Covered by a test of the
    reviewer's reproduction (sign-out while the new QR is still being written) and mutants Q24–Q26.
  - **A change of the `issued_at` format fails safe.** The record key includes the exact `issued_at`
    string, so any change (time zone, precision) makes the stored record unreadable for that key, and it is
    deleted on the next read. The card then asks the server again and gets the same stable token (D22); no wrong
    code is ever shown.
  - **The account hash is unsalted (a deliberate choice, round 3).** It only keeps the e-mail from being
    readable at a glance. Anyone who can read this storage can also read the token itself, which is the
    valuable part, and a per-device salt kept in the same store would be just as readable. So a salt would
    add code without protecting anything; documented as low risk.
  - **Residual risk, accepted by the owner («Ναι, να τον θυμάται»).** Any script running on the WordPress
    origin can read `localStorage` and so the member's token: another plugin, the theme, or an XSS on any
    page of the site. Such a token works at the counter like a screenshot, so the limits are the same:
    - one free coffee per Athens day, enforced by the server;
    - the staff check of the first name;
    - the administrator's revocation retires it (D22: opening the card elsewhere no longer does).
    It is NOT secret storage.
- **Guard.** `app/scripts/storage-guard.mjs` runs in the packager on the customer bundle's real esbuild
  import graph (app sources and `node_modules`) and in `tests/club/storageGuard.test.ts`:
  - **Normalisation (rounds 3 and 4).** The rules run on a normalised text:
    - esbuild parses the module and reprints it with `minifySyntax`. This is a real parser, so comments
      are removed correctly, also next to a regex literal such as `/a\/*/`. The reprint decodes
      `\uXXXX` / `\u{…}` / `\xXX` escapes, folds `"local" + "Storage"` and `${"local"}Storage`, and
      prints `x["cookie"]` as `x.cookie`;
    - then literal joins such as `["local", "Storage"].join("")` are folded (esbuild leaves joins alone);
    - a module that cannot be parsed is refused (fail closed).
  - **Rules.**
    - Only `src/customer/qrStore.ts` may name a browser store: `localStorage`, plus its own generic
      `storage` names (its variables, the `storage` event).
    - In the app's own sources the names `caches`, `cookie`, `.cookie` and `storage` are refused
      wherever they appear. This also covers destructuring (`const { cookie } = document`,
      `const { storage } = navigator`) and an alias (`d.cookie`).
    - React's development build has a local `caches`, so for `node_modules` these generic names are left
      to the built-bundle checks. The specific APIs (`localStorage`, `sessionStorage`, `indexedDB`,
      `document.cookie`, `navigator.storage`, …) are checked in `node_modules` too.
  - **Built-bundle checks (packager).** The BUILT, minified customer JS must contain `localStorage` exactly
    once and `Storage` exactly once (`EXPECTED_CUSTOMER_LOCAL_STORAGE`, the access in qrStore.ts).
    - The minifier folds escapes, concatenations and constant brackets, so such a bypass in any module
      changes a count. A name split into an array and joined changes the `Storage` count.
    - It bans `cookie` (any occurrence: the bundle has none, and a destructuring keeps the word),
      `sessionStorage`, `indexedDB`, `.cookie`, `caches`, `navigator.storage`, `cookieStore`,
      `openDatabase` and `CacheStorage`, and a destructuring of `storage` out of `navigator`.
  - **Not covered statically:**
    - aliasing that is not a destructuring and uses a run-time key (`const n = navigator; n[k]`);
    - a store name split so that no piece is `Storage` and joined at run time from non-literal parts.
    - Review remains the last net.
  - Mutants Q12–Q22, Q27–Q30.
- **The decision rules, as built:**

| Server says | What the app does |
|---|---|
| `active`, the same `issued_at` kept (this visit or an earlier one) | Show the kept QR. |
| `none` | Delete the account's record; `POST {}`; a `409 qr_exists` (another device or tab was first) → `GET` again once and follow the rules. |
| `active` with nothing (or an older QR) kept | D22: delete the old record, then `POST {}` at once (no button, no question); the server answers with the member's stable token, the one every other device shows; show and keep it. Never a replace. |
| `unavailable` / error / spent request budget | Keep the record. Round 7 (MAJOR-1): if a QR is on screen (a quiet re-check) it **stays**, and a silent retry follows the backoff; on a normal open the record kept for this member (if any) is shown as `unchecked` («Δεν μπορέσαμε να ελέγξουμε τον κωδικό αυτή τη στιγμή…», no claim it was verified) and checked again silently. Without a kept QR: short message; automatic retry after 5 s, 15 s, 30 s, then every 60 s (longer if the budget needs it); «Δοκιμάστε ξανά» link in this state only. Budget: 8 requests per rolling minute, at most 3 of them `POST`. After a `409` the shown code is known to be stale, so a failure then is shown as it is. |
| tab visible again / `online` | `GET` again (at most every 30 s; 5 s in an error state, `unchecked`, or after a failed quiet check); the shown QR stays on screen meanwhile. |
| the member leaves the card (`CustomerApp` effect cleanup, round 7 LOW-3) | `pause()`: every pending retry is cancelled and requests in flight are voided, so no `POST` follows them; `wake()` / `retry()` / another tab's change do nothing; opening the card again resumes with `open()`. |
| `not_member` / `member_disabled` | Delete; explain; no code. |
| `404` (no route: the provenance gate is off) | Delete everything; the approved sample card, unchanged (never replaced by «Φόρτωση…» while the route is unknown). |
| `409 concurrent_change` (or `qr_exists` from an older server) | `GET` again once, then follow the rules; twice in a row → error. |
| `503` (including an unknown COMMIT) / error on the POST | Keep nothing (the record was deleted before the POST); «Δοκιμάστε ξανά» starts again from `GET` (never a replace). |

### Staff routes (`evangelou-club/v1`)

**`POST /members/lookup`** (shared staff account, `evc_lookup_member`). Body
`{"method":"qr","qr_token":…}`; phone lookup is not offered (400 `invalid_request`).

| Status | Body / code |
|---|---|
| 200 | `{"member":{"member_id","first_name"},"status":"active"\|"used_today"\|"inactive"}` |
| 400 | `invalid_qr` (counted as a failure) |
| 404 | `member_not_found`: one byte-identical answer for unknown, replaced, revoked and disabled-member tokens |
| 429 | `rate_limited`, `details.retry_after_seconds = 300`, `Retry-After: 300` |
| 503 | unavailable |

- **Identity:** the reviewed `EVC_Member_Lookup_Service` (capability + session ref before any read;
  limiter before any read, failing closed; `EVC_Qr_Token_Policy::without_expiry()` per D6).
- **Status:** decided by `EVC_Club_Lookup_Backend` from EXACTLY the 05A redeem route's membership
  source (WooCommerce / PMPro read-only + the Club's recorded provenance, no fallback):
  - `denial_reason(now) !== null` → `inactive`. This covers frozen, refunded, cancelled, expired (the
    exclusive end), unverified, pending and none.
  - Otherwise, a ledger row for today's Athens day → `used_today`; else `active`.
- **First name (D8 «μικρό όνομα»):** the FIRST WORD of WP `first_name`, else of WooCommerce
  `billing_first_name`, else `null`; at most 40 characters. Body keys may come in any order.
  Any Unicode space (NBSP, em space, …) separates words, and empty pieces are dropped. A value that
  is not valid UTF-8 is skipped (the next source, else `null`), never shown whole, so the JSON
  answer is always valid.
- **Known gap, left as is (review):** the scan does not look at the coffee catalogue. If the approved
  catalogue were ever empty, the scan would still say `active` while the redeem route answers 503
  (`coffee_catalogue_unavailable`). The shipped v1 catalogue is never empty.
- **The lookup proof:** the reviewed service computes its proof, but the v1 route does not return it
  and the v1 redeem route does not need it. Its TTL (300 s, `EVC_Club_Qr_Services`) therefore has no
  effect today; decide it with v2 prepared redemption.

**The staff app (STAGE-05D).** `app/src/staff/member/`:
- **Pieces.**
  - `memberApi.ts` holds the lookup client. It uses the staff transport (in-memory nonce) and a 10 s bound,
    and parses the D8 body strictly (exact keys, `mem_` + 32 hex, a first name of 1–40 characters or
    `null`). The same file holds the reviewed v1 redeem client (`restClubService`) on that transport.
  - `counterFlow.ts` holds the flow and `CounterScreens.tsx` the screens.
  - The old §4.1 proposal mapper in `restClubService` is removed: lookups through it are refused without a
    request.
- **Mapping.**
  - `404 member_not_found` → "not found", but only WITH the envelope; a bare 404 is `invalid_response`.
  - `400 invalid_qr` → "not a Club QR".
  - `429` → the scan stays closed until `Retry-After` has passed (header, then `details`, else 300 s).
  - `5xx` → unavailable.
  - `401` / `403` → the controller ends the session.
- **Screens.**
  - The first name and the three statuses only.
  - A coffee picker with exactly the v1 catalogue (`coffeeCatalogue.ts`, including «Άλλος καφές»). A test
    reads the PHP `ITEMS` and requires the same codes, labels and order.
  - One `request_id` per intent (`RedemptionIntentTracker`): «Δοκιμή ξανά» resends it, and the coffee is
    locked while the outcome is unknown.
  - No revoke UI (D10).
- **Devices (round 2, owner decision 2026-11-04 «Δεν ξέρω ακόμα, να δουλεύει σε όλες»): iPad, Android and
  a USB hand scanner.**
  - **Camera.** `BarcodeDetector` where the browser has it. Otherwise jsQR (a runtime dependency, pinned
    1.4.0) is loaded **lazily**: it is its own chunk in `staff-app/assets/`, fetched only when the camera
    opens, and `tests/staff/isolation.test.ts` proves it is separate and dynamic.
    - It is fed frames downscaled to at most 480 px from an in-memory canvas every 300 ms.
    - It runs on the main thread. A Worker would need a separately emitted worker file (outside the
      packaged manifest checks) and a `worker-src` CSP rule, for no visible gain at that frame size.
    - The shell CSP (`script-src 'self'`) already allows the chunk, and no Permissions-Policy blocks the
      camera.
  - **Camera leak fixed.** Every start has an AbortController. A stream granted after Close, a screen
    change or a lock is stopped at once. Mutants C14–C16.
  - **Hand scanner.**
    - Greek-layout letters are mapped back to the Latin keys (εωψ_… → evc_…) and Caps Lock is undone.
    - The code must then match exactly `^evc_[0-9a-f]{48}$` (= `EVC_Qr_Token::is_valid_format`). Anything
      else is «not a Club QR» on the tablet and is NOT sent, so it never uses up the D9 limit.
    - Mutants C17–C19.
  - **Retry-After.** An explicit `0` is 0 (not 300). The scan screen redraws every second with a «4:59»
    countdown and re-opens by itself. Mutants C20–C22.
  - **Focus (round 3, review MINOR 4).** With the jsQR fallback almost every tablet "has a camera", so
    the scanner field now has focus WHENEVER the camera is not running: on arrival, after a lookup, after
    a wait, after «Κλείσιμο κάμερας». A USB / Bluetooth hand scanner therefore works without a tap.
    - The field is `inputmode="none"`, so focusing it does not open the on-screen keyboard over the
      screen; a hardware scanner or keyboard still types into it.
    - «Πληκτρολόγηση κωδικού», or a finger or stylus tap on the field (round 4), switches to the normal
      keyboard for typing or pasting.
    - Round 4: focus is never stolen. The field takes focus back only when focus is on the body, inside
      the scan form, or on an element that has just left the page. A staff member on «Πίσω στην αρχική»
      keeps it there when a wait ends. `focus({ preventScroll: true })`.
    - Round 4: the field is described by the screen title and its help (`aria-describedby="evc-scan-title
      evc-scan-help"`), so a screen reader hears «Σάρωση QR μέλους» on arrival.
    - autocomplete / autocorrect / autocapitalize / spellcheck are off.
    - Two fixed live regions (status, alert); the field's label («Σαρωτής QR») tells a screen reader
      where it is.
    - Mutants C23–C24.
  - **After Close.** A camera that answers after «Κλείσιμο κάμερας», a lock or a screen change (granted
    or refused) shows nothing and is stopped. So does a decoder whose chunk fails to load after Close
    (round 4: "aborted", not "unavailable"). The jsQR canvas is emptied on stop. Mutants C28–C30.
- **Gating:** everything runs only while the session is ready AND `features.qr_lookup === true`. A status
  check that turns it off, a lock, an expiry or a 401 / 403 drops the member at once.

**`GET /session` `features.qr_lookup` (STAGE-05D).**
- `EVC_Rest_Lookup_Controller::lookup_ready()` is true only when the lookup routes were registered in this
  request (so `EVC_CLUB_REDEMPTION_SOURCE` is on) AND `EVC_Club_Lookup_Backend::ready()` passes every gate
  of `lookup()` now: staff flag, redemption source, commerce config, provenance, DB config, lookup key of
  at least 32 bytes, a real connection, and schema `MAX(version) >= 3`.
- `ready()` reads no member, token or name and counts nothing.
- **Logging.** Config refusals are quiet: they are kept in `last_reason()` but not logged, because the
  status check runs at every tablet start and tab return. A real outage (`club_db_unavailable`,
  `club_db_schema_too_old`) is logged at most once per reason every 300 s (a transient). The line is
  `Evangelou Club: staff QR not ready (club_db_unavailable, PDOException).` (round 2, mutants QL23–QL24).
  No caching of a true answer (optional in the review, not done: a stale "ready" would only lead to a 503
  scan).
- `coffee_redemption` reports the same value. The lookup gates include the redeem route's constants, Club
  DB and schema, but NOT its catalogue check (`coffee_catalogue_unavailable`). The shipped catalogue is the
  fixed approved list and never empty; the empty-catalogue gap is noted above, and the redeem route would
  still answer 503.
- Any error → `false`. The cost is one Club DB connection per status check while the constants are set.
- Tests in `StaffQrLookupTest`: the shipped wiring → false with nothing logged; every gate; schema 2; DB
  down (logged once, throttled); ready again. Mutants QL17–QL24.
- The shell's bootstrap `features` stays all `false`; the app uses the `/session` answer.

**`POST /admin/members/qr/revoke`** (D6). Users with `evc_manage_club` (administrators). Body
`{"wp_user_id": int}`. The route itself refuses, BEFORE the service:
- any account that carries the Club staff role, even an administrator (403);
- an Application Password request (401);
- a request without a cookie session and a valid `wp_rest` nonce (401).

These route checks are NOT repeated by the service, so each one has its own test and mutant (QL13,
QL14).

| Status | Body / code |
|---|---|
| 200 | `{"revoked":true\|false}` |
| 404 | `member_not_found` |
| 409 | `concurrent_change` |

Only the capability itself is re-checked: the service's administrator path re-checks `evc_manage_club`
through `EVC_Wp_Club_Staff_Authorizer`, which answers ONLY `evc_lookup_member` and `evc_manage_club` (any other capability, e.g. the v2
hand-off ones, is false even if the user has it).

### Failed-scan limit (D9, migration 003 `evc_lookup_failures`)

**What is counted.**
- Only `session:<session_ref>` keys are counted, i.e. one tablet session. The shared account's
  `actor:` key is ignored, so one tablet never blocks the others.
- A call without a session key is refused.

**The window.**
- Fixed 300 s windows aligned to the epoch.
- `allow()` refuses while the current window holds ≥ 10 failures. Across a window boundary up to 19
  can happen in 5 minutes. Scans of the same tablet running IN PARALLEL can each pass `allow()` before
  either records its failure, so a burst may exceed 10 by the number of parallel scans. This is the
  "about 10" the owner decided.
- Successes are not counted.
- **A new login resets the count, and successful logins are not limited.** The session ref is
  derived on the server from the WordPress session token (`EVC_Staff_Session::session_ref`), so each
  new login of the tablet starts a new count of 10. `EVC_Login_Throttle` limits only FAILED password
  attempts; a successful login is not counted or limited. So anyone who knows the tablet password
  can log in again and get about 10 more failed scans, as often as they like. No cap was added: the
  owner confirmed on 2026-11-04 («Όχι, μένει έτσι»): this is an **accepted risk**, with no code change.

**Writes and storage.**
- `record_failure()` is one atomic `INSERT … ON DUPLICATE KEY UPDATE failures = failures + 1` per key.
- It reads the clock once, then prunes ≤ 50 rows older than 1 day, oldest first
  (`ORDER BY window_start_utc`).
- Because failures fail closed (below), an error in the prune `DELETE` (lock wait, deadlock, missing
  grant) also makes that scan `unavailable` (503), even though its failure was already counted.
- Stored key: `sha256("evc-lookup-limit-v1|<action>|session:<ref>")`. No token, id or name is stored.
- **Fail closed, both ways (review MAJOR 1).** A read error in `allow()` makes the lookup
  `unavailable`. So does an error while RECORDING a failure (missing grant, lock timeout, broken
  table): `EVC_Member_Lookup_Service::failed()` then answers `unavailable` instead of `not_found`, so
  an uncountable failure can never be retried without limit. Tests:
  - `SqlLookupRateLimiterTest::test_a_failure_that_cannot_be_written_makes_every_failed_scan_unavailable`
    (only the INSERT fails: 30 bad scans, all `unavailable`);
  - `MemberLookupTest::test_a_failure_that_cannot_be_counted_makes_the_lookup_unavailable`.
  - Mutants L27 and LR8.

**Migration 003.**
- Plain `CREATE TABLE` (fails loudly on a differently shaped table).
- Applied only through `EVC_Migrator` / the STAGE-04A page behind `EVC_CLUB_MIGRATIONS_ENABLED`, whose
  separation checks also verify it (`created_tables()` reads its `CREATE TABLE`).
- **Never applied by accident (review MINOR 5).** The page and the command apply only up to
  `EVC_CLUB_MIGRATIONS_TARGET`:
  - undefined → **2**, so 003 is shown as «ΕΚΚΡΕΜΕΙ, ΔΕΝ ΕΧΕΙ ΕΓΚΡΙΘΕΙ», there is no apply button for
    it, and an apply request (form or CLI) changes nothing;
  - a positive integer (exact type) → that version;
  - anything else → refusal `migrations_target_invalid`.

  The technician sets `define('EVC_CLUB_MIGRATIONS_TARGET', 3);` ONLY after the owner's go-ahead for that database.
  Owner decision D19 (2026-11-10): approved for the STAGING Club database only; production still needs its own approval.
  `EVC_Migrator::migrate(int $up_to)` stops at the target. The argument is REQUIRED (round 4): there
  is no argument-less call that applies everything; PHP refuses one. Tests pass `PHP_INT_MAX`.
- Tests in `ClubMigrationsTest`:
  - `test_by_default_migration_003_is_shown_as_not_approved_and_never_applied`;
  - `…with_target_3_a_database_with_001_and_002_gets_only_003`;
  - `…fresh_database_with_target_3_…_target_1_only_001`;
  - `…invalid_target_is_refused…`;
  - `MigratorTest::test_an_upper_bound_…`.
- Mutants MG37–MG42.
- **Why a constant rather than another mechanism.** It is set by the technician in `wp-config.php`,
  the same place as every other Club switch. The administrator page cannot change it, and its default
  is the safe one. A separate "approve 003" button would put the owner's approval inside the same
  one-click page whose accidental use is the risk.

### Logs and personal data

Logs carry fixed reasons, stages and exception classes only; tests assert that no token, secret or name
appears. The staff answer carries the first name only (D8).

### Tests and mutants

**Integration.**
- `SqlQrTokenStoreTest` (23):
  - a writer really blocked by an uncommitted replacement, lost COMMIT for rotate AND revoke;
  - revoke-vs-rotate and writer races (multi-process);
  - first-issuance decorator;
  - 5 s lock wait;
  - least-privilege grants;
  - the services over SQL;
  - frozen / expired cards never redeem.
- `SqlLookupRateLimiterTest` (10):
  - 10 → block, per tablet / action;
  - window end; no session key; nothing personal stored;
  - prune; failure propagation in `allow()`; an INSERT-only failure → unavailable; grants;
  - the lookup service over SQL;
  - multi-process increments.
- `MigratorTest` / `SqlProvenanceStoreTest` updated for 003.

**WordPress.**
- `StaffQrLookupTest` (wp-redemption, 14):
  - shipped wiring → 503; auth; every gate without connection;
  - the D8 body, first word only, any key order;
  - a leading NBSP or other Unicode space, or invalid UTF-8, never shows the whole name (null instead);
  - inactive / used_today / Athens midnight; byte-identical not-found;
  - D9; the authorizer;
  - D6 revocation; staff-role administrator and Application Password refused.
  - `GET /session` features.qr_lookup only when every gate is ready; the ready check is quiet and reads nothing.
- `CustomerQrTest` (wp-customer, 6).
- `ClubRedemptionBackendTest::test_without_the_constant_no_qr_route_is_registered` (wp-enabled).
- `ClubMigrationsTest` (003 not approved by default; target 3 / 1 / invalid).

**Commerce (CI only).**
- `StaffQrCommerceTest` (wp-commerce-redeem): admin completion → member → QR → scan active + first name
  → redeem → used_today → Athens midnight → cancellation → inactive + redeem 409; an unpaid member scans
  inactive.
- `CustomerQrCommerceTest` (wp-commerce): the shipped customer route with the real constants.

**Mutants.**
- SQL runner: QS1–QS12, LR1–LR8.
- Membership runner: L27 (uncounted failure).
- WP runner, each with named killer tests:
  - QL1–QL24 (modes enabled / redemption; QL17–QL24: the session flag and its throttled outage log);
  - QC1–QC7 (mode customer);
  - MG37–MG42 (migration target).
- Equivalent, not listed:
  - the lock-only and row-count-only store mutants (see above);
  - the backend's second disabled-member check (the service already refuses);
  - the revoke route's own `evc_manage_club` test alone, because the service re-checks the capability
    (the staff-role and Application Password refusals are NOT re-checked: QL13, QL14).

### Not done here

- Phone lookup; v2 prepared redemption.
- Applying 003 anywhere real.
