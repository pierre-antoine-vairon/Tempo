import { NavLink, Outlet } from "react-router-dom";

const linkStyle: React.CSSProperties = {
  display: "block",
  padding: "8px 12px",
  borderRadius: 8,
  textDecoration: "none",
};

export default function DashboardLayout() {
  return (
    <div
      style={{
        display: "grid",
        gridTemplateColumns: "220px 1fr",
        minHeight: "100vh",
        fontFamily: "system-ui",
      }}
    >
      <aside style={{ padding: 12, borderRight: "1px solid #eee" }}>
        <h2 style={{ marginTop: 0 }}>Tempo</h2>

        <nav style={{ display: "grid", gap: 6 }}>
          <NavLink
            to="/"
            end
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#f2f2f2" : "transparent",
            })}
          >
            Home
          </NavLink>
          <NavLink
            to="/sites"
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#f2f2f2" : "transparent",
            })}
          >
            Sites
          </NavLink>
          <NavLink
            to="/workers"
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#f2f2f2" : "transparent",
            })}
          >
            Workers
          </NavLink>
          <NavLink
            to="/rosters"
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#f2f2f2" : "transparent",
            })}
          >
            Rosters
          </NavLink>
          <NavLink
            to="/planning"
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#f2f2f2" : "transparent",
            })}
          >
            Planning
          </NavLink>
          <NavLink
            to="/coverage"
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#f2f2f2" : "transparent",
            })}
          >
            Couverture
          </NavLink>
        </nav>
      </aside>

      <main style={{ padding: 16 }}>
        <Outlet />
      </main>
    </div>
  );
}
