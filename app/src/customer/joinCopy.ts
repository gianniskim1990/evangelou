/*
 * STAGE-07 copy of the in-store join flow. The register field labels and hints, the terms sentence and the offer
 * are the approved UI-02 wording (src/preview/onboardingCopy.ts); what is new is said here. Owner decision D12: no
 * newsletter / marketing choice in this flow at all.
 */
import { ONBOARDING_COPY } from "../preview/onboardingCopy";

export const JOIN_STEPS = ["Λογαριασμός", "Όροι", "Συνδρομή"];

export const JOIN_COPY = {
  register: {
    title: "Ο λογαριασμός σας",
    lede: "Ένας λογαριασμός με e-mail και κωδικό: με αυτόν βλέπετε την κάρτα μέλους σας. Το κινητό βοηθά το ταμείο όταν δεν έχετε την κάρτα μαζί σας.",
    nameLabel: ONBOARDING_COPY.register.nameLabel,
    phoneLabel: ONBOARDING_COPY.register.phoneLabel,
    phoneHint: ONBOARDING_COPY.register.phoneHint,
    emailLabel: ONBOARDING_COPY.register.emailLabel,
    emailHint: ONBOARDING_COPY.register.emailHint,
    passwordLabel: "Κωδικός πρόσβασης",
    passwordHint: "Τουλάχιστον 10 χαρακτήρες. Τον χρειάζεστε για να μπαίνετε στον λογαριασμό σας.",
    submit: "Δημιουργία λογαριασμού",
    busy: "Μια στιγμή…",
    haveAccount: "Έχετε ήδη λογαριασμό;",
    signIn: "Σύνδεση",
    reset: "Επαναφορά κωδικού",
  },
  checkAccount: {
    title: "Ίσως έχετε ήδη λογαριασμό",
    body: "Με αυτό το e-mail δεν μπορεί να δημιουργηθεί νέος λογαριασμός. Αν είναι δικό σας, συνδεθείτε ή επαναφέρετε τον κωδικό σας.",
  },
  terms: {
    title: "Οι όροι του Club",
    lede: "Χρειάζεται μόνο η αποδοχή των όρων. Δεν σας ζητάμε καμία συναίνεση για διαφημίσεις.",
    rowTitle: ONBOARDING_COPY.consents.termsTitle,
    rowBody: ONBOARDING_COPY.consents.termsBody,
    readTerms: "Οι όροι του Club",
    readPrivacy: "Η πολιτική απορρήτου",
    needed: ONBOARDING_COPY.consents.termsNeeded,
    accepted: "Όροι αποδεκτοί.",
    submit: "Συνέχεια",
  },
  offer: {
    account: "Ο λογαριασμός μου",
    note: "Η πληρωμή γίνεται στη σελίδα παραγγελίας του καταστήματος, με τραπεζική μεταφορά. Η συνδρομή ξεκινά όταν το κατάστημα επιβεβαιώσει την πληρωμή.",
  },
  member: {
    title: "Είστε ήδη στο Club",
    lede: "Η συνδρομή σας, ή η πληρωμή της, υπάρχει ήδη. Δείτε την κατάσταση στον λογαριασμό μέλους σας.",
    go: "Στον λογαριασμό μέλους",
  },
  notCustomer: {
    title: "Ο λογαριασμός δεν μπορεί να γίνει μέλος",
    lede: "Είστε συνδεδεμένοι με λογαριασμό του καταστήματος. Αποσυνδεθείτε για να γραφτείτε ως πελάτης.",
    signOut: "Αποσύνδεση",
  },
  unavailable: {
    title: "Η εγγραφή δεν είναι διαθέσιμη",
    lede: "Δεν μπορούμε να ολοκληρώσουμε την εγγραφή αυτή τη στιγμή. Ρωτήστε στο ταμείο ή δοκιμάστε αργότερα.",
    retry: "Δοκιμάστε ξανά",
  },
  errors: {
    notCompleted: "Η εγγραφή δεν ολοκληρώθηκε. Δοκιμάστε ξανά.",
    rateLimited: "Πολλές προσπάθειες από αυτό το δίκτυο. Δοκιμάστε ξανά σε λίγα λεπτά.",
    tryLater: "Η εγγραφή δεν είναι δυνατή αυτή τη στιγμή. Δοκιμάστε ξανά αργότερα ή ρωτήστε στο ταμείο.",
    failed: "Δεν ήταν δυνατή η σύνδεση με το κατάστημα. Δοκιμάστε ξανά.",
    checkFields: (count: number) => (count === 1 ? "Ελέγξτε 1 πεδίο." : `Ελέγξτε ${count} πεδία.`),
  },
  poster: {
    kicker: "Evangelou Club",
    title: "Ένας καφές κάθε μέρα. Γραφτείτε τώρα.",
    lede: "Σκανάρετε τον κωδικό με την κάμερα του κινητού σας και φτιάξτε τον λογαριασμό σας σε ένα λεπτό.",
    qrLabel: "Κωδικός QR για την εγγραφή στο Evangelou Club",
    reload: "Ανανέωση",
    offline: "Χωρίς σύνδεση στο διαδίκτυο: ο κωδικός εξακολουθεί να λειτουργεί.",
  },
} as const;
