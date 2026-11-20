import { useSyncExternalStore } from "react";
import { Btn } from "../preview/ds";
import { INSTALL_COPY, type InstallHintFlow } from "./pwa/installHint";

/**
 * STAGE-08 round 11 (owner decision D26): «Εγκατάσταση στο κινητό», below the member area's screen (never over the
 * QR). Android / Chrome: the browser's own prompt behind «Εγκατάσταση»; iPhone / iPad Safari: the two steps in
 * Apple's Greek labels. Closed once = never again on this device (pwa/installHint.ts).
 */
export function InstallHint({ flow }: { flow: InstallHintFlow }) {
  const { kind } = useSyncExternalStore(flow.subscribe, flow.getState);
  if (kind === null) return null;
  return (
    <aside aria-labelledby="evc-install-title" data-evc-install={kind} className="bg-[var(--stone)] px-5 pt-2 pb-10 md:px-10">
      <div className="mx-auto max-w-[1080px] rounded-[18px] border border-[var(--rule-strong)] bg-[var(--paper)] px-5 py-5 md:px-7">
        <h2 id="evc-install-title" className="t-title text-[var(--roast)]">
          {INSTALL_COPY.title}
        </h2>
        <p className="t-body mt-2 max-w-[52ch] text-[var(--ink-2)]">{kind === "prompt" ? INSTALL_COPY.prompt : INSTALL_COPY.ios}</p>
        <div className="mt-5 flex flex-wrap gap-3">
          {kind === "prompt" && (
            <Btn tone="roast" onClick={() => void flow.install()} data-evc-action="install">
              {INSTALL_COPY.install}
            </Btn>
          )}
          <Btn tone="line" onClick={() => flow.dismiss()} data-evc-action="install-dismiss">
            {kind === "prompt" ? INSTALL_COPY.later : INSTALL_COPY.ok}
          </Btn>
        </div>
      </div>
    </aside>
  );
}
