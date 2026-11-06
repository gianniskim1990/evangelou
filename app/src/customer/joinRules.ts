/*
 * STAGE-07 in-store onboarding: the registration form rules (pure). The SAME rules and messages as the approved
 * CUSTOMER-01 form (src/customerPreview/registration.ts, which the live bundle may not import; a test pins the two
 * together) with the e-mail REQUIRED (owner decision 2026-10-23), plus the password policy of the server
 * (EVC_Onboarding_Rules). The server checks everything again; these checks only spare a round trip.
 */

export type JoinForm = { name: string; phone: string; email: string; password: string };
export type JoinField = keyof JoinForm;
export type JoinErrors = Partial<Record<JoinField, string>>;

export const EMPTY_JOIN_FORM: JoinForm = { name: "", phone: "", email: "", password: "" };
export const NAME_MAX = 80;
export const PASSWORD_MIN = 10;
export const PASSWORD_MAX = 128;
const NAME_CHARS = /^[\p{L}][\p{L}\p{M}' .-]*$/u;
const EMAIL = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;

export const JOIN_MESSAGES = {
  nameMissing: "Συμπληρώστε το ονοματεπώνυμό σας.",
  nameOneWord: "Γράψτε όνομα και επώνυμο.",
  nameChars: "Χρησιμοποιήστε μόνο γράμματα, κενά, παύλα ή απόστροφο.",
  nameLong: `Έως ${NAME_MAX} χαρακτήρες.`,
  phoneMissing: "Συμπληρώστε το κινητό σας.",
  phoneInvalid: "Γράψτε ένα ελληνικό κινητό με 10 ψηφία, π.χ. 69xx xxx xxx.",
  emailMissing: "Συμπληρώστε το e-mail σας.",
  emailInvalid: "Ελέγξτε το e-mail, π.χ. onoma@example.gr.",
  passwordShort: `Τουλάχιστον ${PASSWORD_MIN} χαρακτήρες.`,
  passwordLong: `Έως ${PASSWORD_MAX} χαρακτήρες.`,
  passwordWeak: "Διαλέξτε έναν λιγότερο προβλέψιμο κωδικό, χωρίς το e-mail ή το κινητό σας.",
} as const;

/** The server's field codes (EVC_Onboarding_Rules) -> the same Greek messages as the browser checks. */
export const SERVER_CODES: Record<string, string> = {
  name_missing: JOIN_MESSAGES.nameMissing,
  name_one_word: JOIN_MESSAGES.nameOneWord,
  name_chars: JOIN_MESSAGES.nameChars,
  name_long: JOIN_MESSAGES.nameLong,
  phone_missing: JOIN_MESSAGES.phoneMissing,
  phone_invalid: JOIN_MESSAGES.phoneInvalid,
  email_missing: JOIN_MESSAGES.emailMissing,
  email_invalid: JOIN_MESSAGES.emailInvalid,
  password_short: JOIN_MESSAGES.passwordShort,
  password_long: JOIN_MESSAGES.passwordLong,
  password_weak: JOIN_MESSAGES.passwordWeak,
};

/** "69x xxx xxxx", "+30 69…", "0030 69…" (spaces, dots, dashes, brackets ignored) -> "+3069xxxxxxxx", else null. */
export function canonicalGreekMobile(raw: string): string | null {
  const compact = raw.replace(/[\s().-]/g, "");
  const national = compact.replace(/^(?:\+30|0030)/, "");
  return /^69\d{8}$/.test(national) ? `+30${national}` : null;
}

export function normaliseName(raw: string): string {
  return raw.trim().replace(/\s+/g, " ");
}

const COMMON = ["1234567890", "0123456789", "qwertyuiop", "password12", "password123", "evangelou1", "evangelou123", "kafes12345", "coffee1234", "asdfghjkl1", "1111111111", "abcdefghij", "abc1234567"];

/** The server's password policy (EVC_Onboarding_Rules::password_problem), for an early hint only. */
export function passwordProblem(password: string, email: string, phone: string): string | null {
  const length = [...password].length;
  if (length < PASSWORD_MIN) return JOIN_MESSAGES.passwordShort;
  if (length > PASSWORD_MAX) return JOIN_MESSAGES.passwordLong;
  const lower = password.toLowerCase();
  if (COMMON.includes(lower) || /^(.)\1*$/su.test(password)) return JOIN_MESSAGES.passwordWeak;
  const local = email.trim().toLowerCase().split("@")[0] ?? "";
  if (local.length >= 4 && lower.includes(local)) return JOIN_MESSAGES.passwordWeak;
  const digits = (canonicalGreekMobile(phone) ?? "").replace(/\D/g, "").slice(-8);
  if (digits.length === 8 && password.replace(/\D/g, "").includes(digits)) return JOIN_MESSAGES.passwordWeak;
  return null;
}

export function validateJoin(form: JoinForm): JoinErrors {
  const errors: JoinErrors = {};
  const name = normaliseName(form.name);
  if (name === "") errors.name = JOIN_MESSAGES.nameMissing;
  else if ([...name].length > NAME_MAX) errors.name = JOIN_MESSAGES.nameLong;
  else if (!NAME_CHARS.test(name)) errors.name = JOIN_MESSAGES.nameChars;
  else if (!name.includes(" ")) errors.name = JOIN_MESSAGES.nameOneWord;

  if (form.phone.trim() === "") errors.phone = JOIN_MESSAGES.phoneMissing;
  else if (canonicalGreekMobile(form.phone) === null) errors.phone = JOIN_MESSAGES.phoneInvalid;

  const email = form.email.trim();
  if (email === "") errors.email = JOIN_MESSAGES.emailMissing;
  else if (email.length > 254 || !EMAIL.test(email)) errors.email = JOIN_MESSAGES.emailInvalid;

  const password = passwordProblem(form.password, form.email, form.phone);
  if (password) errors.password = password;
  return errors;
}

export const JOIN_FIELD_ORDER: JoinField[] = ["name", "phone", "email", "password"];

export function firstJoinError(errors: JoinErrors): JoinField | null {
  return JOIN_FIELD_ORDER.find((f) => errors[f] !== undefined) ?? null;
}
