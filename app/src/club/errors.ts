import { ClubApiError, type ClubErrorCode, type ClubErrorDetails } from "./types";

/**
 * Staff-facing Greek copy, chosen by the CLIENT per error code. Server
 * `message` text is never shown, so a technical server error (PHP, SQL,
 * proxy HTML) can never reach the cashier's screen.
 */
export const CLUB_ERROR_MESSAGES: Record<ClubErrorCode, string> = {
  invalid_request: "Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.",
  invalid_phone: "Μη έγκυρος αριθμός τηλεφώνου.",
  invalid_qr: "Μη έγκυρος κωδικός QR.",
  invalid_coffee: "Ο επιλεγμένος καφές δεν είναι διαθέσιμος. Επιλέξτε άλλον.",
  member_not_found: "Δεν βρέθηκε μέλος.",
  membership_inactive: "Η συνδρομή δεν είναι ενεργή.",
  benefit_already_redeemed: "Η σημερινή παροχή έχει ήδη χρησιμοποιηθεί.",
  benefit_not_available: "Η παροχή δεν είναι διαθέσιμη.",
  idempotency_key_reused: "Το αίτημα δεν ήταν δυνατό να επαληθευτεί. Ξεκινήστε νέα καταχώρηση.",
  unauthorized: "Η σύνδεση προσωπικού έληξε. Συνδεθείτε ξανά.",
  forbidden: "Δεν έχετε δικαίωμα για αυτή την ενέργεια.",
  rate_limited: "Πολλά αιτήματα. Περιμένετε λίγο και δοκιμάστε ξανά.",
  server_error: "Δεν ήταν δυνατή η ολοκλήρωση. Δοκιμάστε ξανά.",
  network_error: "Δεν υπάρχει σύνδεση με τον διακομιστή. Δοκιμάστε ξανά.",
  invalid_response: "Μη αναμενόμενη απάντηση από τον διακομιστή. Δοκιμάστε ξανά.",
};

export function clubError(code: ClubErrorCode, details?: ClubErrorDetails, httpStatus?: number): ClubApiError {
  return new ClubApiError(code, CLUB_ERROR_MESSAGES[code], details, httpStatus);
}

/**
 * For a REDEEM attempt: can the server have committed it, or did the server
 * definitely not process it? Either way the SAME request_id must be reused
 * on retry, so these failures keep the intent open:
 *
 * - network_error / invalid_response: response lost or unreadable → outcome unknown.
 * - server_error: the engine reports unknown COMMIT outcomes this way.
 * - rate_limited / unauthorized: not processed; retrying the same id is safe.
 *
 * Every other code is a definitive answer about this payload and closes the
 * intent. Nothing here auto-retries: retry is always an explicit staff action.
 */
const RETRY_SAME_INTENT: ReadonlySet<ClubErrorCode> = new Set<ClubErrorCode>([
  "network_error",
  "invalid_response",
  "server_error",
  "rate_limited",
  "unauthorized",
]);

export function keepsIntentOpen(err: unknown): boolean {
  return !(err instanceof ClubApiError) || RETRY_SAME_INTENT.has(err.code);
}
