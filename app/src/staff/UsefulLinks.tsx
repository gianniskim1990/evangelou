import { useState } from "react";
import type { StaffLink, StaffLinkKey } from "./config";

/*
 * STAGE-08 round 18, owner decision D31 (2026-11-23): «Χρήσιμοι σύνδεσμοι» on the till's home screen. The addresses
 * come from the server (EVC_Club_Links::staff(): only the enabled features, never an administrator page) and were
 * checked as this site's in config.ts. Each one has «Αντιγραφή»: the clipboard API where the browser allows it, else a
 * hidden, selected text field and the older copy command; if both fail the address stays visible to copy by hand.
 */

export const LINK_LABELS: Record<StaffLinkKey, string> = {
  join: "Εγγραφή πελάτη",
  poster: "Αφίσα με QR για εγγραφή",
  member: "Κάρτα πελάτη",
  member_login: "Σύνδεση πελάτη",
  till: "Ταμείο (σύνδεση προσωπικού)",
};

export interface ClipboardEnv {
  clipboard?: { writeText(text: string): Promise<void> } | null;
  secure: boolean;
  /** The older way: a selected, hidden field and document.execCommand("copy"). */
  fallback(text: string): boolean;
}

function documentFallback(text: string): boolean {
  const doc = globalThis.document;
  if (!doc?.body) return false;
  const field = doc.createElement("textarea");
  field.value = text;
  field.setAttribute("readonly", "");
  field.style.position = "fixed";
  field.style.opacity = "0";
  doc.body.appendChild(field);
  field.select();
  let ok = false;
  try {
    ok = typeof doc.execCommand === "function" && doc.execCommand("copy");
  } catch {
    ok = false;
  }
  field.remove();
  return ok;
}

export function browserClipboard(): ClipboardEnv {
  const nav = globalThis.navigator as { clipboard?: { writeText(text: string): Promise<void> } } | undefined;
  return { clipboard: nav?.clipboard ?? null, secure: globalThis.isSecureContext === true, fallback: documentFallback };
}

/** Copies the text: the clipboard API first, then the fallback. true = copied. */
export async function copyText(text: string, env: ClipboardEnv): Promise<boolean> {
  if (env.clipboard && env.secure) {
    try {
      await env.clipboard.writeText(text);
      return true;
    } catch {
      // refused (permissions, focus): try the older way
    }
  }
  return env.fallback(text);
}

export function UsefulLinks({ links, clipboard = browserClipboard() }: { links: StaffLink[]; clipboard?: ClipboardEnv }) {
  const [copied, setCopied] = useState<{ key: StaffLinkKey; ok: boolean } | null>(null);
  if (links.length === 0) return null;
  return (
    <section className="mt-6 rounded-2xl border border-espresso/10 bg-surface p-5" aria-labelledby="evc-staff-links" data-evc-links="staff">
      <h2 id="evc-staff-links" className="mb-1 text-[15px] font-semibold">
        Χρήσιμοι σύνδεσμοι
      </h2>
      <p className="mb-3 text-[12.5px] text-espresso/55">Οι διευθύνσεις του Club σε αυτόν τον ιστότοπο.</p>
      <ul className="m-0 list-none p-0">
        {links.map((link) => (
          <li key={link.key} className="border-t border-espresso/10 py-3 first:border-t-0" data-evc-link={link.key}>
            <span className="block text-[13.5px] font-semibold text-espresso">{LINK_LABELS[link.key]}</span>
            <span className="mt-0.5 block text-[12.5px] break-all text-espresso/65">{link.url}</span>
            <span className="mt-2 flex flex-wrap items-center gap-3">
              <button
                type="button"
                className="min-h-11 rounded-xl border border-espresso/20 bg-surface px-4 text-sm font-semibold"
                data-evc-action="copy-link"
                onClick={() => {
                  void copyText(link.url, clipboard).then((ok) => setCopied({ key: link.key, ok }));
                }}
              >
                Αντιγραφή
              </button>
              <span role="status" aria-live="polite" className="text-[12.5px] text-espresso/60">
                {copied?.key === link.key ? (copied.ok ? "Αντιγράφηκε." : "Δεν αντιγράφηκε· επιλέξτε τη διεύθυνση και αντιγράψτε τη με το χέρι.") : ""}
              </span>
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}
