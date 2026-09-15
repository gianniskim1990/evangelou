import { useState } from "react";
import { clubService } from "../clubService";
import { formatClockTime, formatGreekLongDate, maskPhone } from "../format";
import type { ClubMember, MemberBenefitStatus } from "../types";

function CoffeeIcon({ className }: { className?: string }) {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" className={className}>
      <path d="M5 9h11v5.5A4.5 4.5 0 0 1 11.5 19H9.5A4.5 4.5 0 0 1 5 14.5V9Z" />
      <path d="M16 10.5h1.5a2.25 2.25 0 0 1 0 4.5H16" />
      <path d="M8 6c0-1 .8-1.3.8-2.2M11.5 6c0-1 .8-1.3.8-2.2" />
    </svg>
  );
}

function NotFound({ query, onBack }: { query: string; onBack: () => void }) {
  return (
    <main className="mx-auto max-w-[520px] px-5 pt-10 pb-10 text-center">
      <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-hairline">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" className="text-espresso/55">
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
    } catch (err) {
      setError(err instanceof Error ? err.message : "Η καταχώρηση απέτυχε.");
    } finally {
      setRedeeming(false);
    }
  };

  return (
    <main className="mx-auto max-w-[520px] px-5 pt-8 pb-10">
      <div className="mb-5 rounded-2xl bg-surface p-5 shadow-[0_4px_18px_rgba(30,24,18,0.06)]">
        <div className="mb-3.5 flex items-start justify-between gap-3">
          <h1 className="font-literata text-xl font-semibold">{member.name}</h1>
          <span
            className={`flex-none rounded-full px-2.5 py-1 text-[11px] font-semibold ${
              isActive ? "bg-bronze-dark text-white" : "bg-hairline text-maroon"
            }`}
          >
            {isActive ? "Ενεργό μέλος" : "Μη ενεργό μέλος"}
          </span>
        </div>

        <div className="flex flex-col gap-1.5 text-[13.5px] text-espresso/70">
          <div>
            {isActive ? "Ενεργό έως " : "Η συνδρομή έληξε στις "}
            <span className="font-semibold text-espresso">{formatGreekLongDate(member.validUntil)}</span>
          </div>
          <div>Τηλέφωνο <span className="font-semibold text-espresso">{maskPhone(member.phone)}</span></div>
        </div>
      </div>

      {!isActive && (
        <div className="mb-5 rounded-xl bg-hairline px-4 py-3.5 text-[13px] text-espresso/70">
          Η συνδρομή έχει λήξει — δεν είναι διαθέσιμη καμία παροχή μέλους σήμερα.
        </div>
      )}

      <section className="rounded-2xl border border-espresso/10 bg-surface p-5">
        <h2 className="mb-3.5 text-[12px] font-semibold tracking-[0.14em] text-bronze uppercase">Σημερινή παροχή</h2>

        <div className="mb-4 flex items-center gap-3">
          <span className="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-bronze/12 text-bronze-dark">
            <CoffeeIcon />
          </span>
          <div className="flex-1">
            <div className="text-[15px] font-semibold">Δωρεάν καφές</div>
            {isActive && benefit.state === "available" && <div className="text-[13px] text-bronze-dark">Διαθέσιμος</div>}
            {isActive && benefit.state === "used" && <div className="text-[13px] text-espresso/55">Χρησιμοποιήθηκε σήμερα</div>}
            {!isActive && <div className="text-[13px] text-espresso/45">Μη διαθέσιμη</div>}
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

        {isActive && benefit.state === "used" && (
          <div className="rounded-xl bg-hairline px-4 py-3.5 text-[13px] text-espresso/70">
            Ο σημερινός δωρεάν καφές έχει ήδη χρησιμοποιηθεί
            {benefit.redeemedAt && <> στις <span className="font-semibold text-espresso">{formatClockTime(benefit.redeemedAt)}</span></>}.
          </div>
        )}
      </section>

      <button onClick={onSearchAnother} className="mt-6 w-full text-center text-sm font-semibold text-bronze-dark underline">
        Αναζήτηση άλλου μέλους
      </button>

      {confirming && (
        <div className="fixed inset-0 z-40 flex items-end justify-center bg-espresso/45 sm:items-center" onClick={() => !redeeming && setConfirming(false)}>
          <div
            onClick={(e) => e.stopPropagation()}
            className="w-full max-w-[400px] rounded-t-2xl bg-surface p-6 sm:rounded-2xl"
          >
            <h2 className="font-literata mb-2 text-lg font-semibold">Καταχώρηση παροχής</h2>
            <p className="mb-5 text-sm text-espresso/70">«Να καταχωρηθεί δωρεάν καφές στο μέλος {member.name};»</p>
            {error && <p className="mb-4 text-sm text-maroon">{error}</p>}
            <div className="flex gap-2.5">
              <button
                onClick={() => setConfirming(false)}
                disabled={redeeming}
                className="flex-1 rounded-xl border border-espresso/20 bg-surface py-3 text-sm font-semibold disabled:opacity-40"
              >
                Ακύρωση
              </button>
              <button
                onClick={confirmRedeem}
                disabled={redeeming}
                className="flex-1 rounded-xl border-none bg-bronze-dark py-3 text-sm font-semibold text-white disabled:opacity-60"
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
