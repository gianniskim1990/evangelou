import { useState } from "react";
import { MenuProvider } from "../MenuContext";
import { SettingsProvider } from "../SettingsContext";
import { AdminLogin } from "./AdminLogin";
import { AdminOrderAlertsProvider } from "./AdminOrderAlertsProvider";
import { AdminShell } from "./AdminShell";
import { getStoredPassword } from "./adminApi";

export function AdminApp() {
  const [loggedIn, setLoggedIn] = useState(() => getStoredPassword() !== null);
  const logout = () => setLoggedIn(false);

  return (
    <MenuProvider>
      <SettingsProvider>
        {loggedIn ? (
          <AdminOrderAlertsProvider onAuthExpired={logout}>
            <AdminShell onLogout={logout} />
          </AdminOrderAlertsProvider>
        ) : (
          <AdminLogin onSuccess={() => setLoggedIn(true)} />
        )}
      </SettingsProvider>
    </MenuProvider>
  );
}
