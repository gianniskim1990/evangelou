import type { ClubMember } from "./types";

/**
 * Demo membership records only — this stands in for what a future
 * `GET /club/members` (backed by Paid Memberships Pro + FluentCRM data)
 * would return. Prices/dates here are illustrative, not real member data.
 */
export const mockMembers: ClubMember[] = [
  {
    id: "member-1",
    name: "Μαρία Παπαδοπούλου",
    phone: "6900000001",
    status: "active",
    validUntil: "2026-10-15",
    qrToken: "DEMO-QR-MARIA-0001",
  },
  {
    id: "member-2",
    name: "Γιώργος Νικολάου",
    phone: "6900000002",
    status: "active",
    validUntil: "2026-09-28",
    qrToken: "DEMO-QR-GIORGOS-0002",
  },
  {
    id: "member-3",
    name: "Ελένη Κωνσταντίνου",
    phone: "6900000003",
    status: "expired",
    validUntil: "2026-08-31",
    qrToken: "DEMO-QR-ELENI-0003",
  },
];

/** Used by the demo "Προσομοίωση σάρωσης" button — always resolves to Member 1. */
export const DEMO_QR_TOKEN = mockMembers[0].qrToken;

/** Member 2's coffee is pre-seeded as already used today, at this local time,
 * so the demo can show the "already used" state without a live redemption first. */
export const SEEDED_USED_MEMBER_ID = "member-2";
export const SEEDED_USED_TIME = "10:42";
