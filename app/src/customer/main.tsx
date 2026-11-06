import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../index.css";
import "../preview/preview.css";
import { CONFIG_ELEMENT_ID, parseCustomerConfig } from "./config";
import { CustomerApp } from "./CustomerApp";
import { createCustomerApi, createNonceBox } from "./customerApi";
import { createQrKeeper, MemberQrFlow } from "./memberQr";
import { createCustomerQrApi } from "./qrApi";
import { createStorageQrKeeper } from "./qrStore";
import { createJoinApi } from "./joinApi";
import { bootMode, parseJoinConfig, parsePosterConfig } from "./joinConfig";
import { JoinApp } from "./JoinApp";
import { PosterApp } from "./PosterApp";

/**
 * STAGE-01 REAL customer app entry (vite.customer.config.ts), served only by
 * the WordPress /club-member/ page while EVC_CLUB_CUSTOMER_ENABLED is true.
 * The bootstrap JSON is read once and removed, so the nonce lives in memory
 * only. The ONE thing stored in the browser is the member's own Club QR
 * (STAGE-05D round 2, owner decision: qrStore.ts, the only file allowed to).
 */
const root = document.getElementById("root");
const element = document.getElementById(CONFIG_ELEMENT_ID);
const raw = element?.textContent;
element?.remove();
const FAILED = "Η εφαρμογή του Club δεν μπορεί να ξεκινήσει. Ανανεώστε τη σελίδα.";

// STAGE-07: the same bundle serves the member area (STAGE-01, no "mode"), the in-store join page and the poster.
const mode = bootMode(raw);
if (root) {
  if (mode === "join") {
    const parsed = parseJoinConfig(raw, window.location.origin);
    if (!parsed.ok) root.textContent = FAILED;
    else
      createRoot(root).render(
        <StrictMode>
          <JoinApp config={parsed.config} api={createJoinApi(parsed.config)} navigate={(url) => window.location.assign(url)} />
        </StrictMode>,
      );
  } else if (mode === "poster") {
    const parsed = parsePosterConfig(raw, window.location.origin);
    if (!parsed.ok) root.textContent = FAILED;
    else
      createRoot(root).render(
        <StrictMode>
          <PosterApp config={parsed.config} reload={() => window.location.reload()} />
        </StrictMode>,
      );
  } else {
    const parsed = mode === "member" ? parseCustomerConfig(raw, window.location.origin) : ({ ok: false } as const);
    if (!parsed.ok) {
      root.textContent = FAILED;
    } else {
      const nonce = createNonceBox(parsed.config.nonce);
      // Owner decision 2026-11-04: the phone remembers the QR (qrStore.ts); memory only if storage cannot work.
      const qrFlow = new MemberQrFlow(createCustomerQrApi(parsed.config, nonce), createQrKeeper(createStorageQrKeeper()));
      createRoot(root).render(
        <StrictMode>
          <CustomerApp config={parsed.config} api={createCustomerApi(parsed.config, undefined, undefined, nonce)} qrFlow={qrFlow} navigate={(url) => window.location.assign(url)} />
        </StrictMode>,
      );
    }
  }
}
