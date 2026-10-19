import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../index.css";
import "../preview/preview.css";
import { CustomerJourneyPreview } from "./CustomerJourneyPreview";

/**
 * CUSTOMER-01 LOCAL-ONLY interactive customer Club journey.
 *
 * Opened with `npm run customer:preview` (Vite dev server). It is not an
 * input of any production build, refuses to run outside the dev server, uses
 * only fictional members and an in-memory simulated server, and never makes
 * a request to WordPress, a payment provider or anywhere else.
 */
const root = document.getElementById("root");
if (!import.meta.env.DEV) {
  if (root) root.textContent = "Η προεπισκόπηση πελάτη λειτουργεί μόνο στον τοπικό διακομιστή ανάπτυξης.";
} else if (root) {
  createRoot(root).render(
    <StrictMode>
      <CustomerJourneyPreview />
    </StrictMode>,
  );
}
