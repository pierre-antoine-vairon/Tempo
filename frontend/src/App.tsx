import { BrowserRouter, Routes, Route } from "react-router-dom";

import DashboardLayout from "./layouts/DashboardLayout";
import ProductionLayout from "./layouts/ProductionLayout";

import Home from "./pages/Home";
import SitesPage from "./pages/SitesPage";
import SiteOperatingCalendarPage from "./pages/SiteOperatingCalendarPage";
import WorkersPage from "./pages/WorkersPage";
import RostersPage from "./pages/RostersPage";
import RosterDetailPage from "./pages/RosterDetailPage";
import PlanningPage from "./pages/PlanningPage";
import CoveragePage from "./pages/CoveragePage";
import PurchasingSuppliersPage from "./pages/purchasing/PurchasingSuppliersPage";
import PurchasingMaterialsPage from "./pages/purchasing/PurchasingMaterialsPage";
import PurchasingSupplierDetailPage from "./pages/purchasing/PurchasingSupplierDetailPage";

import ProductionHomePage from "./pages/production/ProductionHomePage";
import ProductionDayPage from "./pages/production/ProductionDayPage";
import ProductionHistoryPage from "./pages/production/ProductionHistoryPage";
import ProductionProductsPage from "./pages/production/ProductionProductsPage";

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<DashboardLayout />}>
          <Route path="/" element={<Home />} />
          <Route path="/sites" element={<SitesPage />} />
          <Route
            path="/sites/:siteId/operating-calendar"
            element={<SiteOperatingCalendarPage />}
          />
          <Route path="/workers" element={<WorkersPage />} />
          <Route path="/rosters" element={<RostersPage />} />
          <Route path="/rosters/:id" element={<RosterDetailPage />} />
          <Route path="/planning" element={<PlanningPage />} />
          <Route path="/coverage" element={<CoveragePage />} />
          <Route path="/purchasing" element={<PurchasingSuppliersPage />} />
          <Route
            path="/purchasing/materials"
            element={<PurchasingMaterialsPage />}
          />
          <Route
            path="/purchasing/suppliers/:supplierId"
            element={<PurchasingSupplierDetailPage />}
          />

          {/* ============================================================
              SECTION PRODUCTION
          ============================================================= */}

          <Route path="/production" element={<ProductionLayout />}>
            <Route index element={<ProductionHomePage />} />

            <Route path="day" element={<ProductionDayPage />} />

            <Route path="history" element={<ProductionHistoryPage />} />

            <Route path="products" element={<ProductionProductsPage />} />
          </Route>
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
