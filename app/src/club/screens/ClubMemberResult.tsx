import { useEffect, useRef, useState } from "react";
import { CLUB_LOOKUP_ERROR_MESSAGE, clubService } from "../clubService";
import { formatClockTime, formatGreekLongDate, membershipBadgeLabel } from "../format";
import { ClubApiError, type ClubMember, type MemberBenefitStatus } from "../types";

function CoffeeIcon({ className }: { className?: string }) {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
      <path d="M5 9h11v5.5A4.5 4.5 0 0 1 11.5 19H9.5A4.5 4.5 0 0 1 5 14.5V9Z" />
      <path d="M16 10.5h1.5a2.25 2.25 0 0 1 0 4.5H16" />
      <path d="M8 6c0-1 .8-1.3.8-2.2M11.5 6c0-1 .8-1.3.8-2.2" />
    </svg>
  );
}

function CheckIcon({ className }: { className?: string }) {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
      <path d="M4 12l5 5L20 6" />
    </svg>
  );
}

function NotFound({ query, onBack }: { query: string; onBack: () => void }) {
  return (
    <main className="mx-auto max-w-[520px] px-5 pt-10 pb-10 text-center md:max-w-[640px] md:px-8 md:pt-16">
      <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-hairline">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" className="text-espresso/55" aria-hidden="true">
          <circle cx="11" cy="11" r="6.5" />
          <path d="m20 20-4-4M9 11h4" />
        </svg>
      </div>
      <h1 className="font-literata mb-2 text-xl font-semibold">Δεν βρέθηκε μέλος</h1>
      <p className="mb-1 text-sm text-espresso/60">«Ελέγξτε τον αριθμό τηλεφώνου και δοκιμάστε ξανά.»</p>
      <p className="mb-7 text-xs text-espresso/40">Αναζήτηση: {query}</p>
      <button onClick={onBack} className="rounded-xl border-none bg-bronze-dark px-6 py-3.5 text-sm font-semibold text-white">
        Νέα αναζήτηση
      </button>
    </main>
  );
}

export function ClubMemberResult({
  member,
  benefit,
  notFoundQuery,
  onRedeemed,
  onSearchAnother,
}: {
  member: ClubMember | null;
  benefit: MemberBenefitStatus | null;
  notFoundQuery?: string;
  onRedeemed: (benefit: MemberBenefitStatus) => void;
  onSearchAnother: () => void;
}) {
  const [confirming, setConfirming] = useState(false);
  const [redeeming, setRedeeming] = useState(false);
  const [error, setError] = useState("");
  const [justRedeemed, setJustRedeemed] = useState(false);
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (confirming) cancelRef.current?.focus();
  }, [confirming]);

  useEffect(() => {
    if (!confirming) return;
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape" && !redeeming) setConfirming(false);
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [confirming, redeeming]);

  useEffect(() => {
    if (!justRedeemed) return;
    const t = setTimeout(() => setJustRedeemed(false), 2200);
    return () => clearTimeout(t);
  }, [justRedeemed]);

  if (!member || !benefit) {
    return <NotFound query={notFoundQuery ?? ""} onBack={onSearchAnother} />;
  }

  const isActive = member.status === "active";

  const confirmRedeem = async () => {
    setRedeeming(true);
    setError("");
    try {
      const result = await clubService.redeemBenefit(member.id, "free_coffee");
      onRedeemed(result);
      setConfirming(false);
      setJustRedeemed(true);
    } catch (err) {
      if (err instanceof ClubApiError && err.code === "benefit_already_redeemed") {
        // Another till (or a retried request) already redeemed this today —
        // sync the UI to the real "used" state instead of showing an error,
        // per docs/evangelou-club-api.md §9.
        const details = err.details as { businessDate?: string; redeemedAt?: string } | undefined;
        onRedeemed({
          state: "used",
          businessDate: details?.businessDate ?? "",
          redeemedAt: details?.redeemedAt ?? null,
        });
        setConfirming(false);
        return;
      }
      setError(err instanceof Error ? err.message : CLUB_LOOKUP_ERROR_MESSAGE);
    } finally {
      setRedeeming(false);
    }
  };

  return (
    <main className="mx-auto max-w-[520px] px-5 pt-8 pb-10 md:max-w-[640px] md:px-8 md:pt-12">
      <div className="mb-5 rounded-2xl bg-surface p-5 shadow-[0_4px_18px_rgba(30,24,18,0.06)] md:p-6">
        <div className="mb-3.5 flex items-start justify-between gap-3">
          <h1 className="font-literata text-xl font-semibold">{member.name}</h1>
          <span
            className={`flex-none rounded-full px-2.5 py-1 text-[11px] font-semibold ${
              isActive ? "bg-bronze-dark text-white" : "bg-hairline text-maroon"
            }`}
          >
            {membershipBadgeLabel(member.status)}
          </span>
        </div>

        <div className="flex flex-col gap-1.5 text-[13.5px] text-espresso/70">
          <div>
            {member.validUntil ? (
              <>
                {isActive ? "Ενεργό έως " : "Η συνδρομή έληξε στις "}
                <span className="font-semibold text-espresso">{formatGreekLongDate(member.validUntil)}</span>
              </>
            ) : isActive ? (
              "Ενεργή συνδρομή"
            ) : (
              "Η συνδρομή δεν είναι ενεργή"
            )}
          </div>
          <div>Τηλέφωνο <span className="font-semibold text-espresso">{member.phoneMasked}</span></div>
        </div>
      </div>

      <section className="rounded-2xl border border-espresso/10 bg-surface p-5 md:p-6">
        <h2 className="mb-3.5 text-[15px] font-semibold">Σημερινή παροχή</h2>

        <div className="mb-4 flex items-center gap-3">
          <span className="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-bronze/12 text-bronze-dark">
            <CoffeeIcon />
          </span>
          <div className="flex-1">
            <div className="text-[15px] font-semibold">Δωρεάν καφές</div>
            {isActive && benefit.state === "available" && <div className="text-[13px] text-bronze-dark">Διαθέσιμος</div>}
            {isActive && benefit.state === "used" && <div className="text-[13px] text-espresso/55">Χρησιμοποιήθηκε σήμερα</div>}
            {!isActive && <div className="text-[13px] text-espresso/45">Μη διαθέσιμος</div>}
          </div>
        </div>

        {isActive && benefit.state === "available" && (
          <button
            onClick={() => setConfirming(true)}
            className="w-full rounded-xl border-none bg-bronze-dark py-3.5 text-sm font-semibold text-white"
          >
            Καταχώρηση δωρεάν καφέ
          </button>
        )}

        {isActive && benefit.state === "used" && benefit.redeemedAt && (
          justRedeemed ? (
            <div className="flex items-center gap-2.5 rounded-xl bg-bronze/12 px-4 py-3.5 text-[13px] font-semibold text-bronze-dark">
              <CheckIcon />
              Ο καφές καταχωρήθηκε στις {formatClockTime(benefit.redeemedAt)}
            </div>
          ) : (
            <div className="rounded-xl bg-hairline px-4 py-3.5 text-[13px] text-espresso/70">
              Χρησιμοποιήθηκε σήμερα στις <span className="font-semibold text-espresso">{formatClockTime(benefit.redeemedAt)}</span>.
            </div>
          )
        )}
      </section>

      <button onClick={onSearchAnother} className="mt-6 w-full text-center text-sm font-semibold text-bronze-dark underline">
        Αναζήτηση άλλου μέλους
      </button>

      {confirming && (
        <div
          className="fixed inset-0 z-40 flex items-end justify-center bg-espresso/45 sm:items-center"
          onClick={() => !redeeming && setConfirming(false)}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="redeem-dialog-title"
            onClick={(e) => e.stopPropagation()}
            className="w-full max-w-[400px] rounded-t-2xl bg-surface p-6 sm:rounded-2xl"
          >
            <h2 id="redeem-dialog-title" className="font-literata mb-2 text-lg font-semibold">Καταχώρηση παροχής</h2>
            <p className="mb-5 text-sm text-espresso/70">«Να καταχωρηθεί δωρεάν καφές στο μέλος {member.name};»</p>
            {error && <p className="mb-4 text-sm text-maroon">{error}</p>}
            <div className="flex gap-2.5">
              <button
                ref={cancelRef}
                onClick={() => setConfirming(false)}
                disabled={redeeming}
                className="min-w-0 flex-1 rounded-xl border border-espresso/20 bg-surface py-3 text-sm font-semibold disabled:opacity-40"
              >
                Ακύρωση
              </button>
              <button
                onClick={confirmRedeem}
                disabled={redeeming}
                className="min-w-0 flex-1 rounded-xl border-none bg-bronze-dark py-3 text-sm font-semibold text-white disabled:opacity-60"
              >
                {redeeming ? "Καταχώρηση…" : "Καταχώρηση"}
              </button>
            </div>
          </div>
        </div>
      )}
    </main>
  );
}
