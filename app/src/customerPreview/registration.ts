/*
 * CUSTOMER-01 LOCAL-ONLY customer preview: registration form rules (pure).
 *
 * Client-side checks for a sensible form only; the future server validates
 * everything again. Open owner decisions are NOT settled here:
 *  - D7 (e-mail optional or required) is a preview switch, default required,
 *    because the approved register screen says the e-mail carries the
 *    membership confirmation and the payment receipt;
 *  - foreign phone numbers are open: only Greek mobiles (69…) are accepted,
 *    with the same canonical form as the server lookup core (+3069…).
 */

export type RegistrationForm = { name: string; phone: string; email: string };
export type RegistrationField = keyof RegistrationForm;
export type RegistrationErrors = Partial<Record<RegistrationField, string>>;

export const EMPTY_FORM: RegistrationForm = { name: "", phone: "", email: "" };

export const NAME_MAX = 80;
const NAME_CHARS = /^[\p{L}][\p{L}\p{M}' .-]*$/u;
const EMAIL = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;

export const MESSAGES = {
  nameMissing: "Συμπληρώστε το ονοματεπώνυμό σας.",
  nameOneWord: "Γράψτε όνομα και επώνυμο.",
  nameChars: "Χρησιμοποιήστε μόνο γράμματα, κενά, παύλα ή απόστροφο.",
  nameLong: `Έως ${NAME_MAX} χαρακτήρες.`,
  phoneMissing: "Συμπληρώστε το κινητό σας.",
  phoneInvalid: "Γράψτε ένα ελληνικό κινητό με 10 ψηφία, π.χ. 69xx xxx xxx.",
  emailMissing: "Συμπληρώστε το e-mail σας.",
  emailInvalid: "Ελέγξτε το e-mail, π.χ. onoma@example.gr.",
} as const;

/** "69x xxx xxxx", "+30 69…", "0030 69…" (spaces, dots, dashes, brackets ignored) -> "+3069xxxxxxxx", else null. */
export function canonicalGreekMobile(raw: string): string | null {
  const compact = raw.replace(/[\s().-]/g, "");
  const national = compact.replace(/^(?:\+30|0030)/, "");
  return /^69\d{8}$/.test(national) ? `+30${national}` : null;
}

/** "+306912345614" -> "69•• ••• •14" (the approved masked format). */
export function maskMobile(canonical: string): string {
  const national = canonical.replace(/^\+30/, "");
  return `${national.slice(0, 2)}•• ••• •${national.slice(-2)}`;
}

export function normaliseName(raw: string): string {
  return raw.trim().replace(/\s+/g, " ");
}

export function validateRegistration(form: RegistrationForm, emailRequired: boolean): RegistrationErrors {
  const errors: RegistrationErrors = {};
  const name = normaliseName(form.name);
  if (name === "") errors.name = MESSAGES.nameMissing;
  else if (name.length > NAME_MAX) errors.name = MESSAGES.nameLong;
  else if (!NAME_CHARS.test(name)) errors.name = MESSAGES.nameChars;
  else if (!name.includes(" ")) errors.name = MESSAGES.nameOneWord;

  if (form.phone.trim() === "") errors.phone = MESSAGES.phoneMissing;
  else if (canonicalGreekMobile(form.phone) === null) errors.phone = MESSAGES.phoneInvalid;

  const email = form.email.trim();
  if (email === "") {
    if (emailRequired) errors.email = MESSAGES.emailMissing;
  } else if (email.length > 254 || !EMAIL.test(email)) errors.email = MESSAGES.emailInvalid;
  return errors;
}

/** Field order for focusing the first invalid field. */
export const FIELD_ORDER: RegistrationField[] = ["name", "phone", "email"];

export function firstInvalid(errors: RegistrationErrors): RegistrationField | null {
  return FIELD_ORDER.find((f) => errors[f] !== undefined) ?? null;
}
