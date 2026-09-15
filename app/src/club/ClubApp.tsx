import { useState } from "react";
import { ClubShell } from "./ClubShell";
import { CLUB_LOOKUP_ERROR_MESSAGE, DEMO_QR_TOKEN, clubService } from "./clubService";
import { ClubEntry } from "./screens/ClubEntry";
import { ClubHome } from "./screens/ClubHome";
import { ClubMemberResult } from "./screens/ClubMemberResult";
import { ClubQrScanner } from "./screens/ClubQrScanner";
import type { ClubMember, MemberBenefitStatus } from "./types";

type ClubView = "entry" | "home" | "qr" | "result";

export function ClubApp() {
  const [view, setView] = useState<ClubView>("entry");
  const [searching, setSearching] = useState(false);
  const [searchError, setSearchError] = useState("");
  const [scanning, setScanning] = useState(false);
  const [scanError, setScanError] = useState("");
  const [activeMember, setActiveMember] = useState<ClubMember | null>(null);
  const [activeBenefit, setActiveBenefit] = useState<MemberBenefitStatus | null>(null);
  const [notFoundQuery, setNotFoundQuery] = useState("");

  const enter = () => setView("home");

  const exit = () => {
    setView("entry");
    setActiveMember(null);
    setActiveBenefit(null);
    setNotFoundQuery("");
    setSearchError("");
    setScanError("");
  };

  const backToHome = () => {
    setView("home");
    setSearchError("");
    setScanError("");
  };

  const runSearch = async (phone: string) => {
    setSearching(true);
    setSearchError("");
    try {
      const { member, benefit } = await clubService.findMemberByPhone(phone);
      setActiveMember(member);
      setActiveBenefit(benefit);
      setNotFoundQuery(member ? "" : phone);
      setView("result");
    } catch {
      setSearchError(CLUB_LOOKUP_ERROR_MESSAGE);
    } finally {
      setSearching(false);
    }
  };

  const runQrScan = async () => {
    setScanning(true);
    setScanError("");
    try {
      const { member, benefit } = await clubService.findMemberByQrToken(DEMO_QR_TOKEN);
      setActiveMember(member);
      setActiveBenefit(benefit);
      setNotFoundQuery("");
      setView("result");
    } catch {
      setScanError(CLUB_LOOKUP_ERROR_MESSAGE);
    } finally {
      setScanning(false);
    }
  };

  if (view === "entry") return <ClubEntry onEnter={enter} />;

  return (
    <ClubShell onBack={view === "result" || view === "qr" ? backToHome : undefined} onExit={exit}>
      {view === "home" && (
        <ClubHome onScanQr={() => setView("qr")} onSearch={runSearch} searching={searching} error={searchError} />
      )}
      {view === "qr" && <ClubQrScanner onSimulateScan={runQrScan} scanning={scanning} error={scanError} />}
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
