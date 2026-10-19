/*
 * Approved UI-02 customer onboarding copy (register, consents, offer), shared by
 * the design preview and the CUSTOMER-01 customer journey preview so the
 * wording cannot drift between them.
 */

export const ONBOARDING_STEPS = ["Στοιχεία", "Ενημερώσεις", "Συνδρομή"];

/** Approved onboarding copy, shared with the customer journey preview so the wording cannot drift. */
export const ONBOARDING_COPY = {
  register: {
    title: "Τα στοιχεία σας",
    lede: "Μόνο όσα χρειάζεται το ταμείο για να σας αναγνωρίζει. Το κινητό βοηθά όταν δεν έχετε την κάρτα μαζί σας.",
    nameLabel: "Ονοματεπώνυμο",
    phoneLabel: "Κινητό τηλέφωνο",
    phoneHint: "Για αναζήτηση στο ταμείο. Δεν το χρησιμοποιούμε για διαφημίσεις.",
    emailLabel: "E-mail",
    emailHint: "Για την επιβεβαίωση της συνδρομής και την απόδειξη πληρωμής.",
  },
  consents: {
    title: "Εσείς επιλέγετε",
    lede: "Δύο ξεχωριστές επιλογές. Το newsletter δεν είναι συνδρομή στο Club και δεν δίνει δωρεάν καφέ.",
    termsTitle: "Όροι Club και απόρρητο",
    termsBody: "Αποδέχομαι τους όρους του Evangelou Club και την πολιτική απορρήτου.",
    newsTitle: "Newsletter",
    newsBody: "Θέλω να λαμβάνω νέα, προσφορές και εκδηλώσεις με e-mail. Μπορώ να διαγραφώ οποιαδήποτε στιγμή, χωρίς να επηρεαστεί η συνδρομή μου.",
    termsNeeded: "Για να συνεχίσετε χρειάζεται η αποδοχή των όρων.",
  },
  offer: {
    title: "Η συνδρομή σας",
    lede: "Μία τιμή, ένας μήνας, ένας καφές την ημέρα. Ανανεώνετε μόνο όταν το θέλετε.",
    included: [
      "Ένας δωρεάν καφές κάθε ημερολογιακή ημέρα, ώρα Ελλάδας.",
      "Ψηφιακή κάρτα μέλους για το ταμείο.",
      "Το ιστορικό των καφέδων σας στον λογαριασμό σας.",
      "Χειροκίνητη ανανέωση, χωρίς αυτόματη χρέωση.",
    ],
    terms: "Η συνδρομή διαρκεί έναν ημερολογιακό μήνα από την επιβεβαίωση της πληρωμής και λήγει με ώρα Ελλάδας. Στους πρώτους μήνες η ανανέωση γίνεται χειροκίνητα.",
  },
} as const;

export function consentsLine(terms: boolean, news: boolean): string {
  return terms ? (news ? "Όροι αποδεκτοί. Newsletter: ναι." : "Όροι αποδεκτοί. Newsletter: όχι.") : ONBOARDING_COPY.consents.termsNeeded;
}
