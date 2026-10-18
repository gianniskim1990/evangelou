import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../../index.css";
import "../../preview/preview.css";
import { CounterPreview } from "./CounterPreview";

/**
 * UI-04O LOCAL-ONLY integrated staff counter-flow preview.
 *
 * Opened with `npm run counter:preview` (Vite dev server). It is not an input
 * of any production build, refuses to run outside the dev server, uses only
 * deterministic fake members and an in-memory fake server, and never makes a
 * request to WordPress or anywhere else.
 */
const root = document.getElementById("root");
if (!import.meta.env.DEV) {
  if (root) root.textContent = "Η προεπισκόπηση ταμείου λειτουργεί μόνο στον τοπικό διακομιστή ανάπτυξης.";
} else if (root) {
  createRoot(root).render(
    <StrictMode>
      <CounterPreview />
    </StrictMode>,
  );
}
