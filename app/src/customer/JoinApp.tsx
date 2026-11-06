import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import { Offer, Onboarding } from "../preview/CustomerPreview";
import { Btn, ConsentRow, Field, Logo } from "../preview/ds";
import type { CustomerScreen } from "../preview/screens";
import type { JoinApi } from "./joinApi";
import type { JoinConfig, JoinStep } from "./joinConfig";
import { JOIN_COPY, JOIN_STEPS } from "./joinCopy";
import { EMPTY_JOIN_FORM, firstJoinError, SERVER_CODES, validateJoin, type JoinErrors, type JoinField, type JoinForm } from "./joinRules";

/*
 * STAGE-07 in-store QR onboarding (served by WordPress at /club/join/, flag-gated): the approved UI-02 onboarding
 * (register -> consents -> offer) connected to the join routes. Additive only: the steps are named for this flow,
 * the consents step has ONLY the Club terms (owner decision D12: no newsletter / marketing choice here), and the
 * offer goes to the shop's own WooCommerce checkout. Every navigation goes to a URL from the server's bootstrap or
 * answer, all checked to be this site (joinConfig.ts / joinApi.ts): no open redirect.
 */

type Navigate = (url: string) => void;
type Notice = { title?: string; body: string; links?: boolean } | null;

const noGo = (_s: CustomerScreen) => {};

function Plain({ title, lede, children }: { title: string; lede: string; children?: ReactNode }) {
  return (
    <div className="min-h-[calc(100svh-52px)] bg-[var(--stone)]">
      <header className="mx-auto flex max-w-[1180px] items-center justify-between px-5 py-5 md:px-10 md:py-7">
        <Logo className="h-8 md:h-10" />
      </header>
      <main className="mx-auto max-w-[1180px] px-5 pb-16 md:px-10">
        <h1 className="t-headline mt-10 max-w-[16ch] text-[var(--roast)]">{title}</h1>
        <p className="t-lede mt-4 max-w-[42ch] text-[var(--ink-2)]">{lede}</p>
        {children && <div className="mt-9 flex flex-wrap items-center gap-x-6 gap-y-4">{children}</div>}
      </main>
    </div>
  );
}

const linkClass = "t-label -my-1 py-3 text-[var(--ink-2)] underline decoration-[var(--rule-strong)] underline-offset-[6px] hover:text-[var(--ink)]";

export function JoinApp({ config, api, navigate }: { config: JoinConfig; api: JoinApi; navigate: Navigate }) {
  const [step, setStep] = useState<JoinStep>(config.step);
  const [form, setForm] = useState<JoinForm>(EMPTY_JOIN_FORM);
  const [honeypot, setHoneypot] = useState("");
  const [errors, setErrors] = useState<JoinErrors>({});
  const [notice, setNotice] = useState<Notice>(null);
  const [terms, setTerms] = useState(false);
  const [busy, setBusy] = useState(false);
  const [announce, setAnnounce] = useState("");
  const busyRef = useRef(false);
  const root = useRef<HTMLDivElement>(null);
  const moved = useRef(false);
  const noticeRef = useRef<HTMLDivElement>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  const phoneRef = useRef<HTMLInputElement>(null);
  const emailRef = useRef<HTMLInputElement>(null);
  const passwordRef = useRef<HTMLInputElement>(null);

  // A new step moves keyboard and screen-reader focus to its heading (not on the first render).
  useEffect(() => {
    document.title = "Εγγραφή · Evangelou Club";
    if (!moved.current) return;
    const heading = root.current?.querySelector("h1");
    if (heading instanceof HTMLElement) {
      heading.setAttribute("tabindex", "-1");
      heading.focus();
    }
    window.scrollTo?.({ top: 0 });
  }, [step]);

  useEffect(() => {
    if (notice) noticeRef.current?.focus();
  }, [notice]);

  const go = (next: JoinStep) => {
    moved.current = true;
    setNotice(null);
    setStep(next);
  };

  /** One request at a time (a double tap never sends twice). */
  const once = async (work: () => Promise<void>) => {
    if (busyRef.current) return;
    busyRef.current = true;
    setBusy(true);
    try {
      await work();
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  };

  const set = (field: JoinField) => (value: string) => {
    setForm((f) => ({ ...f, [field]: value }));
    setErrors((e) => (e[field] === undefined ? e : { ...e, [field]: undefined }));
  };

  const showErrors = (found: JoinErrors) => {
    setErrors(found);
    const first = firstJoinError(found);
    if (first) {
      setAnnounce(JOIN_COPY.errors.checkFields(Object.keys(found).length));
      ({ name: nameRef, phone: phoneRef, email: emailRef, password: passwordRef })[first].current?.focus();
    }
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setNotice(null);
    const found = validateJoin(form);
    if (Object.keys(found).length > 0) return showErrors(found);
    setErrors({});
    void once(async () => {
      const r = await api.register(form, honeypot);
      if (r.kind === "ok" || r.kind === "signed_in") return navigate(config.joinUrl);
      if (r.kind === "invalid") {
        const mapped: JoinErrors = {};
        for (const [field, code] of Object.entries(r.fields)) mapped[field as JoinField] = SERVER_CODES[code] ?? JOIN_COPY.errors.notCompleted;
        return showErrors(Object.keys(mapped).length > 0 ? mapped : {});
      }
      if (r.kind === "check_account") return setNotice({ title: JOIN_COPY.checkAccount.title, body: JOIN_COPY.checkAccount.body, links: true });
      if (r.kind === "rate_limited") return setNotice({ body: JOIN_COPY.errors.rateLimited });
      if (r.kind === "not_completed") return setNotice({ body: JOIN_COPY.errors.notCompleted });
      if (r.kind === "unavailable") return go("unavailable");
      setNotice({ body: JOIN_COPY.errors.failed });
    });
  };

  const acceptTerms = () =>
    once(async () => {
      const r = await api.acceptTerms();
      if (r.kind === "ok") return go("offer");
      if (r.kind === "signed_out") return navigate(config.joinUrl);
      if (r.kind === "unavailable") return go("unavailable");
      setNotice({ body: JOIN_COPY.errors.failed });
    });

  const pay = () =>
    void once(async () => {
      const r = await api.checkout();
      if (r.kind === "checkout" || r.kind === "member") return navigate(r.url);
      if (r.kind === "terms_required") return go("terms");
      if (r.kind === "signed_out") return navigate(config.joinUrl);
      if (r.kind === "unavailable") return go("unavailable");
      setNotice({ body: JOIN_COPY.errors.failed });
    });

  const noticeBlock = notice && (
    <div ref={noticeRef} tabIndex={-1} role="alert" className="mt-8 rounded-[14px] border border-[var(--rule-strong)] bg-[var(--paper)] px-5 py-4 outline-none" data-evc-join="notice">
      {notice.title && <p className="t-title text-[var(--ink)]">{notice.title}</p>}
      <p className="t-body mt-1 text-[var(--ink-2)]">{notice.body}</p>
      {notice.links && (
        <p className="mt-3 flex flex-wrap gap-x-6 gap-y-2">
          <a href={config.loginUrl} className={linkClass}>{JOIN_COPY.register.signIn}</a>
          <a href={config.lostPasswordUrl} className={linkClass}>{JOIN_COPY.register.reset}</a>
        </p>
      )}
    </div>
  );

  let body: ReactNode;
  if (step === "register") {
    const c = JOIN_COPY.register;
    body = (
      <Onboarding step={0} go={noGo} steps={JOIN_STEPS} title={c.title} lede={c.lede}>
        <form noValidate onSubmit={submit} aria-busy={busy || undefined}>
          <div className="grid gap-6">
            <Field label={c.nameLabel} value={form.name} edit={{ id: "evc-join-name", name: "name", onChange: set("name"), error: errors.name, required: true, autoComplete: "name" }} inputRef={nameRef} />
            <Field label={c.phoneLabel} value={form.phone} type="tel" hint={c.phoneHint} edit={{ id: "evc-join-phone", name: "phone", onChange: set("phone"), error: errors.phone, required: true, autoComplete: "tel", inputMode: "tel" }} inputRef={phoneRef} />
            <Field label={c.emailLabel} value={form.email} type="email" hint={c.emailHint} edit={{ id: "evc-join-email", name: "email", onChange: set("email"), error: errors.email, required: true, autoComplete: "email", inputMode: "email" }} inputRef={emailRef} />
            <Field label={c.passwordLabel} value={form.password} type="password" hint={c.passwordHint} edit={{ id: "evc-join-password", name: "password", onChange: set("password"), error: errors.password, required: true, autoComplete: "new-password" }} inputRef={passwordRef} />
          </div>
          {/* Honeypot: invisible to people and to assistive technology; a bot that fills it gets nothing. */}
          <div aria-hidden="true" style={{ position: "absolute", left: "-10000px", width: "1px", height: "1px", overflow: "hidden" }}>
            <label htmlFor="evc-join-website">Website</label>
            <input id="evc-join-website" name="website" type="text" tabIndex={-1} autoComplete="off" value={honeypot} onChange={(e) => setHoneypot(e.target.value)} />
          </div>
          {noticeBlock}
          <div className="mt-9 flex flex-wrap items-center gap-x-6 gap-y-4">
            <Btn type="submit" disabled={busy} className="px-8">{busy ? c.busy : c.submit}</Btn>
          </div>
          <p className="t-body mt-8 text-[var(--ink-2)]">
            {c.haveAccount}{" "}
            <a href={config.loginUrl} className={linkClass}>{c.signIn}</a>
          </p>
        </form>
      </Onboarding>
    );
  } else if (step === "terms") {
    const c = JOIN_COPY.terms;
    body = (
      <Onboarding step={1} go={noGo} steps={JOIN_STEPS} title={c.title} lede={c.lede}>
        <div className="border-t border-[var(--rule-strong)]">
          <ConsentRow checked={terms} onChange={setTerms} required title={c.rowTitle} body={c.rowBody} />
        </div>
        {(config.termsUrl || config.privacyUrl) && (
          <p className="mt-4 flex flex-wrap gap-x-6 gap-y-2">
            {config.termsUrl && <a href={config.termsUrl} className={linkClass}>{c.readTerms}</a>}
            {config.privacyUrl && <a href={config.privacyUrl} className={linkClass}>{c.readPrivacy}</a>}
          </p>
        )}
        <p className="t-small mt-4 text-[var(--ink-2)]" aria-live="polite">
          {terms ? c.accepted : c.needed}
        </p>
        {noticeBlock}
        <div className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
          <Btn onClick={() => void acceptTerms()} disabled={!terms || busy} className="px-8">{c.submit}</Btn>
        </div>
      </Onboarding>
    );
  } else if (step === "offer") {
    body = (
      <>
        <Offer go={noGo} steps={JOIN_STEPS} onPay={pay} secondary={{ label: JOIN_COPY.offer.account, onClick: () => navigate(config.memberUrl) }} live={{ note: JOIN_COPY.offer.note }} />
        {notice && <div className="mx-auto max-w-[1180px] px-5 pb-12 md:px-10">{noticeBlock}</div>}
      </>
    );
  } else if (step === "member") {
    const c = JOIN_COPY.member;
    body = (
      <Plain title={c.title} lede={c.lede}>
        <Btn onClick={() => navigate(config.memberUrl)} className="px-8">{c.go}</Btn>
      </Plain>
    );
  } else if (step === "not_customer") {
    const c = JOIN_COPY.notCustomer;
    body = (
      <Plain title={c.title} lede={c.lede}>
        <a href={config.logoutUrl} className={linkClass}>{c.signOut}</a>
      </Plain>
    );
  } else {
    const c = JOIN_COPY.unavailable;
    body = (
      <Plain title={c.title} lede={c.lede}>
        <Btn onClick={() => navigate(config.joinUrl)} className="px-8">{c.retry}</Btn>
      </Plain>
    );
  }

  return (
    <div ref={root} data-evc-customer="join" data-evc-join-step={step}>
      {body}
      <p role="status" aria-live="polite" className="sr-only">
        {announce}
      </p>
    </div>
  );
}
