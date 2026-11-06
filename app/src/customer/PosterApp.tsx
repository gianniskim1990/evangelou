import { useEffect, useState } from "react";
import { Btn, Logo } from "../preview/ds";
import { JOIN_COPY } from "./joinCopy";
import type { PosterConfig } from "./joinConfig";
import { QrSvg } from "./MemberQrPanel";

/*
 * STAGE-07 tablet poster (served by WordPress at /club/join/poster/ to the shop only). A large QR that encodes ONLY
 * the public join URL (with the fixed marker src=store): no secret, nothing about anyone. Offline-tolerant: once
 * loaded, it needs no network at all (the QR is drawn in the page); a reload button for the shop.
 */
export function PosterApp({ config, reload, online = () => (typeof navigator === "undefined" ? true : navigator.onLine) }: { config: PosterConfig; reload: () => void; online?: () => boolean }) {
  const [isOnline, setOnline] = useState(online());
  useEffect(() => {
    const update = () => setOnline(online());
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    return () => {
      window.removeEventListener("online", update);
      window.removeEventListener("offline", update);
    };
  }, [online]);
  const c = JOIN_COPY.poster;
  return (
    <div data-evc-customer="poster" className="flex min-h-[100svh] flex-col items-center justify-center gap-8 bg-[var(--stone)] px-6 py-10 text-center">
      <Logo className="h-12" />
      <div>
        <p className="t-label text-[var(--ink-2)]">{c.kicker}</p>
        <h1 className="t-headline mx-auto mt-3 max-w-[18ch] text-[var(--roast)]">{c.title}</h1>
        <p className="t-lede mx-auto mt-4 max-w-[40ch] text-[var(--ink-2)]">{c.lede}</p>
      </div>
      <div className="rounded-[22px] bg-white p-5 shadow-sm">
        <QrSvg text={config.joinUrl} size={420} label={c.qrLabel} kind="join" />
      </div>
      {!isOnline && (
        <p className="t-small max-w-[40ch] text-[var(--ink-2)]" role="status">
          {c.offline}
        </p>
      )}
      <Btn tone="roast" onClick={reload} className="px-8">
        {c.reload}
      </Btn>
    </div>
  );
}
