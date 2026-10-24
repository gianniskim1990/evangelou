import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../index.css";
import "../preview/preview.css";
import { CONFIG_ELEMENT_ID, parseCustomerConfig } from "./config";
import { CustomerApp } from "./CustomerApp";
import { createCustomerApi } from "./customerApi";

/**
 * STAGE-01 REAL customer app entry (vite.customer.config.ts), served only by
 * the WordPress /club-member/ page while EVC_CLUB_CUSTOMER_ENABLED is true.
 * The bootstrap JSON is read once and removed, so the nonce lives in memory
 * only. Nothing is stored in the browser.
 */
const root = document.getElementById("root");
const element = document.getElementById(CONFIG_ELEMENT_ID);
const parsed = parseCustomerConfig(element?.textContent, window.location.origin);
element?.remove();

if (root) {
  if (!parsed.ok) {
    root.textContent = "Η εφαρμογή του Club δεν μπορεί να ξεκινήσει. Ανανεώστε τη σελίδα.";
  } else {
    createRoot(root).render(
      <StrictMode>
        <CustomerApp config={parsed.config} api={createCustomerApi(parsed.config)} navigate={(url) => window.location.assign(url)} />
      </StrictMode>,
    );
  }
}
