import { BrowserRouter, Routes, Route } from "react-router-dom";
import DashboardLayout from "./layouts/DashboardLayout";
import Home from "./pages/Home";
import SitesPage from "./pages/SitesPage";
import WorkersPage from "./pages/WorkersPage";
import RostersPage from "./pages/RostersPage";
import RosterDetailPage from "./pages/RosterDetailPage";
import PlanningPage from "./pages/PlanningPage";
import CoveragePage from "./pages/CoveragePage";

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<DashboardLayout />}>
          <Route path="/" element={<Home />} />
          <Route path="/sites" element={<SitesPage />} />
          <Route path="/workers" element={<WorkersPage />} />
          <Route path="/rosters" element={<RostersPage />} />
          <Route path="/rosters/:id" element={<RosterDetailPage />} />
          <Route path="/planning" element={<PlanningPage />} />
          <Route path="/coverage" element={<CoveragePage />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
