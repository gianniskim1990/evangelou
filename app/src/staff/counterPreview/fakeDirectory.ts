import { MEMBERS, type PreviewMember } from "../../preview/previewData";

/**
 * UI-04O LOCAL-ONLY counter-flow preview: the deterministic fake member
 * directory behind the fake server.
 *
 * Every person is one of the approved synthetic UI-02 preview members
 * (previewData.ts); nothing here is real. Each gets:
 *  - an opaque public member id in the real v2 format (`mem_` + 32 hex);
 *  - a QR token in the real format (`evc_` + 48 hex) — in production the
 *    server stores only its SHA-256, here it is a plain map key;
 *  - a full FAKE mobile number that matches the member's masked phone
 *    (69 10 000 0 + the two visible digits).
 * The browser never needs the full number to display anything: the member
 * summary carries only the masked form, exactly like the v1 lookup.
 */

export interface DirectoryEntry {
  /** The approved synthetic preview member (name, masked phone, state, favourite coffee). */
  readonly person: PreviewMember;
  readonly memberId: string;
  readonly qrToken: string;
  /** Canonical +30 form, as the v2 phone canonicaliser would produce it. */
  readonly phone: string;
}

const hex = (n: number, width: number) => n.toString(16).padStart(width, "0");

export const FAKE_DIRECTORY: readonly DirectoryEntry[] = MEMBERS.map((person, i) => {
  const lastTwo = person.phoneMasked.replace(/\D/g, "").slice(-2);
  return Object.freeze({
    person,
    memberId: `mem_c0ffee${hex(i + 1, 26)}`,
    qrToken: `evc_5ca1ab1e${hex(i + 1, 40)}`,
    phone: `+3069100000${lastTwo}`,
  });
});

/** A QR token that was rotated away (the card shows an old code): the server answers not_found. */
export const ROTATED_QR_TOKEN = `evc_5ca1ab1e${hex(0xdead, 40)}`;
/** A number registered on two member accounts: the server answers ambiguous and shows no one. */
export const SHARED_PHONE = "+306910000099";

export function entryById(memberId: string): DirectoryEntry | null {
  return FAKE_DIRECTORY.find((e) => e.memberId === memberId) ?? null;
}

export function entryByPreviewId(previewId: string): DirectoryEntry {
  const entry = FAKE_DIRECTORY.find((e) => e.person.id === previewId);
  if (!entry) throw new Error(`unknown preview member ${previewId}`);
  return entry;
}

/**
 * Canonical Greek mobile (+3069XXXXXXXX) or null, mirroring the PHP
 * EVC_Phone_Canonical rules for mobiles: optional +30 / 0030 prefix, spaces
 * ignored, exactly ten digits starting with 69. Foreign numbers are an OPEN
 * owner decision and are refused here.
 */
export function canonicalGreekMobile(input: string): string | null {
  const compact = input.replace(/[\s-]/g, "");
  const digits = compact.startsWith("+30") ? compact.slice(3) : compact.startsWith("0030") ? compact.slice(4) : compact;
  return /^69\d{8}$/.test(digits) ? `+30${digits}` : null;
}
