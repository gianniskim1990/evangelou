export type ClubMembershipStatus = "active" | "expired";

export type CoffeeBenefitState = "available" | "used" | "unavailable";

/**
 * A club member as the (future) Evangelou Club REST API would return it.
 * `qrToken` stands in for whatever the printed/digital membership QR will
 * encode in production — here it is just a demo lookup key.
 */
export interface ClubMember {
  id: string;
  name: string;
  phone: string;
  status: ClubMembershipStatus;
  validUntil: string;
  qrToken: string;
}

/** Today's free-coffee benefit for one member, recomputed per calendar day. */
export interface MemberBenefitStatus {
  state: CoffeeBenefitState;
  redeemedAt?: string;
}

export interface MemberLookup {
  member: ClubMember | null;
  benefit: MemberBenefitStatus | null;
}

export type BenefitType = "free_coffee";
