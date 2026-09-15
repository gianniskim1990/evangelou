import { useState } from "react";
import { ClubShell } from "./ClubShell";
import { DEMO_QR_TOKEN, clubService } from "./clubService";
import { ClubEntry } from "./screens/ClubEntry";
import { ClubHome } from "./screens/ClubHome";
import { ClubMemberResult } from "./screens/ClubMemberResult";
import { ClubQrScanner } from "./screens/ClubQrScanner";
import type { ClubMember, MemberBenefitStatus } from "./types";

type ClubView = "entry" | "home" | "qr" | "result";

export function ClubApp() {
  const [view, setView] = useState<ClubView>("entry");
  const [searching, setSearching] = useState(false);
  const [activeMember, setActiveMember] = useState<ClubMember | null>(null);
  const [activeBenefit, setActiveBenefit] = useState<MemberBenefitStatus | null>(null);
  const [notFoundQuery, setNotFoundQuery] = useState("");

  const enter = () => setView("home");

  const exit = () => {
    setView("entry");
    setActiveMember(null);
    setActiveBenefit(null);
    setNotFoundQuery("");
  };

  const backToHome = () => setView("home");

  const runSearch = async (phone: string) => {
    setSearching(true);
    try {
      const { member, benefit } = await clubService.findMemberByPhone(phone);
      setActiveMember(member);
      setActiveBenefit(benefit);
      setNotFoundQuery(member ? "" : phone);
      setView("result");
    } finally {
      setSearching(false);
    }
  };

  const runQrScan = async () => {
    const { member, benefit } = await clubService.findMemberByQrToken(DEMO_QR_TOKEN);
    setActiveMember(member);
    setActiveBenefit(benefit);
    setNotFoundQuery("");
    setView("result");
  };

  if (view === "entry") return <ClubEntry onEnter={enter} />;

  return (
    <ClubShell onBack={view === "result" || view === "qr" ? backToHome : undefined} onExit={exit}>
      {view === "home" && <ClubHome onScanQr={() => setView("qr")} onSearch={runSearch} searching={searching} />}
      {view === "qr" && <ClubQrScanner onSimulateScan={runQrScan} />}
      {view === "result" && (
        <ClubMemberResult
          member={activeMember}
          benefit={activeBenefit}
          notFoundQuery={notFoundQuery}
          onRedeemed={setActiveBenefit}
          onSearchAnother={backToHome}
        />
      )}
    </ClubShell>
  );
}
