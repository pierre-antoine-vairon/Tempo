import { NavLink, Outlet } from "react-router-dom";

const linkStyle: React.CSSProperties = {
  padding: "9px 14px",
  borderRadius: 8,
  textDecoration: "none",
  color: "#334155",
  fontSize: 14,
  fontWeight: 500,
};

export default function ProductionLayout() {
  return (
    <div>
      {/* ================================================================
          EN-TÊTE DE LA SECTION PRODUCTION
      ================================================================= */}

      <header
        style={{
          marginBottom: 24,
        }}
      >
        <div
          style={{
            marginBottom: 20,
          }}
        >
          <h1
            style={{
              margin: 0,
              fontSize: 28,
              color: "#0f172a",
            }}
          >
            Production
          </h1>

          <p
            style={{
              margin: "6px 0 0",
              color: "#64748b",
            }}
          >
            Suivi et gestion de la production
          </p>
        </div>

        {/* ==============================================================
            NAVIGATION INTERNE PRODUCTION
        =============================================================== */}

        <nav
          style={{
            display: "flex",
            gap: 6,
            flexWrap: "wrap",
            paddingBottom: 14,
            borderBottom: "1px solid #e2e8f0",
          }}
        >
          <NavLink
            to="/production"
            end
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#e2e8f0" : "transparent",
              color: isActive ? "#0f172a" : "#64748b",
            })}
          >
            Vue d'ensemble
          </NavLink>

          <NavLink
            to="/production/day"
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#e2e8f0" : "transparent",
              color: isActive ? "#0f172a" : "#64748b",
            })}
          >
            Production du jour
          </NavLink>

          <NavLink
            to="/production/history"
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#e2e8f0" : "transparent",
              color: isActive ? "#0f172a" : "#64748b",
            })}
          >
            Historique
          </NavLink>

          <NavLink
            to="/production/products"
            style={({ isActive }) => ({
              ...linkStyle,
              background: isActive ? "#e2e8f0" : "transparent",
              color: isActive ? "#0f172a" : "#64748b",
            })}
          >
            Produits
          </NavLink>
        </nav>
      </header>

      {/* ================================================================
          PAGE PRODUCTION ACTIVE
      ================================================================= */}

      <Outlet />
    </div>
  );
}
