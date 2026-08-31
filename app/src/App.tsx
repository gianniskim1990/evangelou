import { useApp } from "./AppContext";
import { BackHeader } from "./components/BackHeader";
import { BottomNav } from "./components/BottomNav";
import { CartBar } from "./components/CartBar";
import { CartDrawer } from "./components/CartDrawer";
import { TopHeader } from "./components/TopHeader";
import { Catalog } from "./screens/Catalog";
import { Checkout } from "./screens/Checkout";
import { Confirmation } from "./screens/Confirmation";
import { Configurator } from "./screens/Configurator";
import { DesktopHome } from "./screens/DesktopHome";
import { Home } from "./screens/Home";
import { Status } from "./screens/Status";

const CHROME_SCREENS = new Set(["home", "catalog", "configurator"]);
const BARE_SCREENS = new Set(["checkout", "confirmation", "status"]);

function App() {
  const { screen, cart, cartCount, cartOpen } = useApp();

  const showTopHeader = CHROME_SCREENS.has(screen);
  const showBackHeader = BARE_SCREENS.has(screen);
  const showBottomNav = CHROME_SCREENS.has(screen);
  const showCartBar = CHROME_SCREENS.has(screen) && cartCount > 0 && !cartOpen;

  const rootPadBottom = BARE_SCREENS.has(screen) ? 24 : cart.length > 0 ? 150 : 90;
  const isHome = screen === "home";

  return (
    <div className={`relative mx-auto min-h-screen bg-cream font-[Commissioner,sans-serif] text-espresso max-w-[480px] ${isHome ? "lg:max-w-none" : ""}`}>
      {/* Phone-width experience: identical at every viewport for every screen except
          the home screen, which hands off to the desktop sidebar layout at lg+. */}
      <div className={isHome ? "lg:hidden" : ""} style={{ paddingBottom: rootPadBottom }}>
        {showTopHeader && <TopHeader />}
        {showBackHeader && <BackHeader />}

        {screen === "home" && <Home />}
        {screen === "catalog" && <Catalog />}
        {screen === "configurator" && <Configurator />}
        {screen === "checkout" && <Checkout />}
        {screen === "confirmation" && <Confirmation />}
        {screen === "status" && <Status />}

        {showCartBar && <CartBar />}
        {showBottomNav && <BottomNav />}
      </div>

      {isHome && (
        <div className="hidden lg:block">
          <DesktopHome />
        </div>
      )}

      <CartDrawer />
    </div>
  );
}

export default App;
