import { useState } from "react";
import { MenuProvider } from "../MenuContext";
import { AdminLogin } from "./AdminLogin";
import { AdminPanel } from "./AdminPanel";
import { getStoredPassword } from "./adminApi";

export function AdminApp() {
  const [loggedIn, setLoggedIn] = useState(() => getStoredPassword() !== null);

  return (
    <MenuProvider>
      {loggedIn ? <AdminPanel onLogout={() => setLoggedIn(false)} /> : <AdminLogin onSuccess={() => setLoggedIn(true)} />}
    </MenuProvider>
  );
}
