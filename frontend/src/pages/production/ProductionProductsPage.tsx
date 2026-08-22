import { useCallback, useEffect, useMemo, useState } from "react";
import { apiGet, apiPut } from "../../api";

type ProductFamily = {
  id: number;
  name: string;
  display_order: number;
  is_active: boolean;
};

type ProductCategory = {
  id: number;
  name: string;
  display_order: number;
  is_active: boolean;
};

type Product = {
  id: number;
  name: string;
  conservation: string | null;
  display_order: number;
  is_active: boolean;

  category: ProductCategory;
  family: ProductFamily;
};

type ProductsResponse = {
  ok: true;

  org_id: number;
  site_id: number;

  products_count: number;
  active_count: number;
  inactive_count: number;

  products: Product[];
};

type StatusFilter = "all" | "active" | "inactive";

type SetProductActiveResponse = {
  ok: true;
  changed: boolean;

  product: {
    id: number;
    name: string;
    is_active: boolean;
  };

  message: string;
};

type UpdateProductResponse = {
  ok: true;
  product: Product;
  message: string;
};

export default function ProductionProductsPage() {
  const [products, setProducts] = useState<Product[]>([]);

  const [loading, setLoading] = useState(true);

  const [error, setError] = useState<string | null>(null);

  const [search, setSearch] = useState("");

  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");

  const [totalCount, setTotalCount] = useState(0);

  const [activeCount, setActiveCount] = useState(0);

  const [inactiveCount, setInactiveCount] = useState(0);

  const [updatingProductId, setUpdatingProductId] = useState<number | null>(
    null,
  );

  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const [editingProductId, setEditingProductId] = useState<number | null>(null);

  const [editName, setEditName] = useState("");

  const [editCategoryId, setEditCategoryId] = useState<number | null>(null);

  const [editConservation, setEditConservation] = useState("");

  const [savingEdit, setSavingEdit] = useState(false);

  /*
  |--------------------------------------------------------------------------
  | Site MVP
  |--------------------------------------------------------------------------
  */

  const siteId = 1;

  /*
  |--------------------------------------------------------------------------
  | Chargement
  |--------------------------------------------------------------------------
  */

  const loadProducts = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const response = await apiGet<ProductsResponse>(
        `/production/products?site_id=${siteId}`,
      );

      setProducts(response.products);

      setTotalCount(response.products_count);

      setActiveCount(response.active_count);

      setInactiveCount(response.inactive_count);
    } catch (err) {
      setProducts([]);

      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger les produits.",
      );
    } finally {
      setLoading(false);
    }
  }, [siteId]);

  async function toggleProductActive(product: Product) {
    const nextIsActive = !product.is_active;

    /*
     * Une désactivation a un impact métier :
     * le produit ne sera plus ajouté aux nouvelles feuilles.
     *
     * On demande donc confirmation.
     */
    if (!nextIsActive) {
      const confirmed = window.confirm(
        `Désactiver "${product.name}" ?\n\nLe produit ne sera plus ajouté aux nouvelles feuilles de production. Les anciennes feuilles resteront inchangées.`,
      );

      if (!confirmed) {
        return;
      }
    }

    try {
      setUpdatingProductId(product.id);

      setError(null);
      setSuccessMessage(null);

      const response = await apiPut<
        SetProductActiveResponse,
        {
          site_id: number;
          is_active: boolean;
        }
      >(`/production/products/${product.id}/active`, {
        site_id: siteId,
        is_active: nextIsActive,
      });

      /*
       * Mise à jour locale immédiate.
       *
       * Pas besoin de recharger les 67 produits.
       */
      setProducts((currentProducts) =>
        currentProducts.map((currentProduct) =>
          currentProduct.id === product.id
            ? {
                ...currentProduct,
                is_active: response.product.is_active,
              }
            : currentProduct,
        ),
      );

      /*
       * Mise à jour des compteurs.
       */
      if (response.changed) {
        setActiveCount((current) => current + (nextIsActive ? 1 : -1));

        setInactiveCount((current) => current + (nextIsActive ? -1 : 1));
      }

      setSuccessMessage(response.message);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de modifier l'état du produit.",
      );
    } finally {
      setUpdatingProductId(null);
    }
  }

  function startEditingProduct(product: Product) {
    setEditingProductId(product.id);

    setEditName(product.name);

    setEditCategoryId(product.category.id);

    setEditConservation(product.conservation ?? "J");

    setError(null);
    setSuccessMessage(null);
  }

  function cancelEditingProduct() {
    setEditingProductId(null);
    setEditName("");
    setEditCategoryId(null);
    setEditConservation("");
  }

  async function saveProductEdit() {
    if (editingProductId === null || editCategoryId === null) {
      return;
    }

    const trimmedName = editName.trim();

    if (trimmedName === "") {
      setError("Le nom du produit ne peut pas être vide.");
      return;
    }

    try {
      setSavingEdit(true);
      setError(null);
      setSuccessMessage(null);

      const response = await apiPut<
        UpdateProductResponse,
        {
          site_id: number;
          name: string;
          category_id: number;
          conservation: string;
        }
      >(`/production/products/${editingProductId}`, {
        site_id: siteId,
        name: trimmedName,
        category_id: editCategoryId,
        conservation: editConservation,
      });

      setProducts((currentProducts) =>
        currentProducts.map((product) =>
          product.id === editingProductId ? response.product : product,
        ),
      );

      setSuccessMessage(response.message);

      cancelEditingProduct();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de modifier le produit.",
      );
    } finally {
      setSavingEdit(false);
    }
  }

  useEffect(() => {
    void loadProducts();
  }, [loadProducts]);

  /*
  |--------------------------------------------------------------------------
  | Recherche + filtre
  |--------------------------------------------------------------------------
  */

  const availableCategories = useMemo(() => {
    const categories = new Map<
      number,
      ProductCategory & {
        family: ProductFamily;
      }
    >();

    for (const product of products) {
      if (!categories.has(product.category.id)) {
        categories.set(product.category.id, {
          ...product.category,
          family: product.family,
        });
      }
    }

    return Array.from(categories.values()).sort((a, b) => {
      if (a.family.display_order !== b.family.display_order) {
        return a.family.display_order - b.family.display_order;
      }

      if (a.display_order !== b.display_order) {
        return a.display_order - b.display_order;
      }

      return a.name.localeCompare(b.name, "fr");
    });
  }, [products]);

  const filteredProducts = useMemo(() => {
    const normalizedSearch = search.trim().toLocaleLowerCase("fr");

    return products.filter((product) => {
      /*
       * Filtre actif / inactif
       */
      if (statusFilter === "active" && !product.is_active) {
        return false;
      }

      if (statusFilter === "inactive" && product.is_active) {
        return false;
      }

      /*
       * Recherche
       */
      if (normalizedSearch === "") {
        return true;
      }

      const searchableText = [
        product.name,
        product.category.name,
        product.family.name,
        product.conservation ?? "",
      ]
        .join(" ")
        .toLocaleLowerCase("fr");

      return searchableText.includes(normalizedSearch);
    });
  }, [products, search, statusFilter]);

  /*
  |--------------------------------------------------------------------------
  | Regroupement famille -> catégorie
  |--------------------------------------------------------------------------
  */

  const families = useMemo(() => {
    const grouped = new Map<
      number,
      {
        family: ProductFamily;
        categories: Map<
          number,
          {
            category: ProductCategory;
            products: Product[];
          }
        >;
      }
    >();

    for (const product of filteredProducts) {
      if (!grouped.has(product.family.id)) {
        grouped.set(product.family.id, {
          family: product.family,
          categories: new Map(),
        });
      }

      const family = grouped.get(product.family.id)!;

      if (!family.categories.has(product.category.id)) {
        family.categories.set(product.category.id, {
          category: product.category,
          products: [],
        });
      }

      family.categories.get(product.category.id)!.products.push(product);
    }

    return Array.from(grouped.values());
  }, [filteredProducts]);

  /*
  |--------------------------------------------------------------------------
  | Chargement
  |--------------------------------------------------------------------------
  */

  if (loading) {
    return (
      <div style={{ padding: 24 }}>
        <h2>Produits</h2>
        <p>Chargement...</p>
      </div>
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Affichage
  |--------------------------------------------------------------------------
  */

  return (
    <div style={{ padding: 24 }}>
      {/* ================================================================
          EN-TÊTE
      ================================================================= */}

      <div
        style={{
          marginBottom: 28,
        }}
      >
        <h2
          style={{
            margin: 0,
          }}
        >
          Produits
        </h2>

        <p
          style={{
            marginTop: 6,
            marginBottom: 0,
            color: "#64748b",
          }}
        >
          Gérez les produits utilisés dans les feuilles de production.
        </p>
      </div>

      {/* ================================================================
          ERREUR
      ================================================================= */}

      {error && (
        <div
          style={{
            padding: 14,
            marginBottom: 20,
            border: "1px solid #fecaca",
            background: "#fef2f2",
            borderRadius: 8,
            color: "#991b1b",
          }}
        >
          {error}
        </div>
      )}

      {successMessage && (
        <div
          style={{
            padding: 14,
            marginBottom: 20,
            border: "1px solid #bbf7d0",
            background: "#f0fdf4",
            borderRadius: 8,
            color: "#166534",
          }}
        >
          {successMessage}
        </div>
      )}

      {editingProductId !== null && (
        <div
          style={{
            padding: 18,
            marginBottom: 22,
            border: "1px solid #cbd5e1",
            borderRadius: 10,
            background: "white",
          }}
        >
          <strong
            style={{
              display: "block",
              marginBottom: 14,
              fontSize: 17,
            }}
          >
            Modifier le produit
          </strong>

          <div
            style={{
              display: "grid",
              gridTemplateColumns: "minmax(220px, 1fr) 220px 140px",
              gap: 12,
              marginBottom: 14,
            }}
          >
            <div>
              <label
                style={{
                  display: "block",
                  marginBottom: 5,
                  color: "#64748b",
                  fontSize: 13,
                }}
              >
                Nom
              </label>

              <input
                type="text"
                value={editName}
                onChange={(event) => setEditName(event.target.value)}
                style={{
                  width: "100%",
                  boxSizing: "border-box",
                  padding: "9px 10px",
                  border: "1px solid #cbd5e1",
                  borderRadius: 8,
                }}
              />
            </div>

            <div>
              <label
                style={{
                  display: "block",
                  marginBottom: 5,
                  color: "#64748b",
                  fontSize: 13,
                }}
              >
                Catégorie
              </label>

              <select
                value={editCategoryId ?? ""}
                onChange={(event) =>
                  setEditCategoryId(Number(event.target.value))
                }
                style={{
                  width: "100%",
                  padding: "9px 10px",
                  border: "1px solid #cbd5e1",
                  borderRadius: 8,
                  background: "white",
                }}
              >
                {availableCategories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.family.name}
                    {" — "}
                    {category.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label
                style={{
                  display: "block",
                  marginBottom: 5,
                  color: "#64748b",
                  fontSize: 13,
                }}
              >
                Conservation
              </label>

              <select
                value={editConservation}
                onChange={(event) => setEditConservation(event.target.value)}
                style={{
                  width: "100%",
                  padding: "9px 10px",
                  border: "1px solid #cbd5e1",
                  borderRadius: 8,
                  background: "white",
                }}
              >
                <option value="J">J</option>

                <option value="J+1">J+1</option>

                <option value="J+2">J+2</option>

                <option value="J+3">J+3</option>
              </select>
            </div>
          </div>

          <div
            style={{
              display: "flex",
              gap: 10,
            }}
          >
            <button
              type="button"
              onClick={cancelEditingProduct}
              disabled={savingEdit}
              style={{
                padding: "8px 13px",
                border: "1px solid #cbd5e1",
                borderRadius: 8,
                background: "white",
                cursor: "pointer",
              }}
            >
              Annuler
            </button>

            <button
              type="button"
              onClick={() => void saveProductEdit()}
              disabled={savingEdit}
              style={{
                padding: "8px 13px",
                border: 0,
                borderRadius: 8,
                background: "#0f172a",
                color: "white",
                fontWeight: 600,
                cursor: savingEdit ? "not-allowed" : "pointer",
              }}
            >
              {savingEdit ? "Enregistrement..." : "Enregistrer"}
            </button>
          </div>
        </div>
      )}

      {/* ================================================================
          COMPTEURS
      ================================================================= */}

      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(3, minmax(140px, 220px))",
          gap: 12,
          marginBottom: 22,
        }}
      >
        <CounterCard label="Total" value={totalCount} />

        <CounterCard label="Actifs" value={activeCount} />

        <CounterCard label="Inactifs" value={inactiveCount} />
      </div>

      {/* ================================================================
          RECHERCHE + FILTRES
      ================================================================= */}

      <div
        style={{
          display: "flex",
          gap: 12,
          alignItems: "center",
          flexWrap: "wrap",
          marginBottom: 30,
        }}
      >
        <input
          type="search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Rechercher un produit..."
          style={{
            width: 320,
            maxWidth: "100%",
            padding: "10px 12px",
            border: "1px solid #cbd5e1",
            borderRadius: 8,
            background: "white",
          }}
        />

        <div
          style={{
            display: "flex",
            gap: 6,
            padding: 4,
            borderRadius: 9,
            background: "#f1f5f9",
          }}
        >
          <FilterButton
            active={statusFilter === "all"}
            onClick={() => setStatusFilter("all")}
          >
            Tous
          </FilterButton>

          <FilterButton
            active={statusFilter === "active"}
            onClick={() => setStatusFilter("active")}
          >
            Actifs
          </FilterButton>

          <FilterButton
            active={statusFilter === "inactive"}
            onClick={() => setStatusFilter("inactive")}
          >
            Inactifs
          </FilterButton>
        </div>

        <span
          style={{
            color: "#64748b",
            fontSize: 14,
          }}
        >
          {filteredProducts.length} produit(s) affiché(s)
        </span>
      </div>

      {/* ================================================================
          AUCUN RÉSULTAT
      ================================================================= */}

      {families.length === 0 && (
        <div
          style={{
            padding: 24,
            border: "1px solid #e2e8f0",
            borderRadius: 10,
            background: "white",
            color: "#64748b",
          }}
        >
          Aucun produit ne correspond à votre recherche.
        </div>
      )}

      {/* ================================================================
          FAMILLES
      ================================================================= */}

      {families.map(({ family, categories }) => {
        const categoriesArray = Array.from(categories.values());

        const familyProductsCount = categoriesArray.reduce(
          (total, category) => total + category.products.length,
          0,
        );

        return (
          <section
            key={family.id}
            style={{
              marginBottom: 38,
            }}
          >
            <div
              style={{
                display: "flex",
                gap: 10,
                alignItems: "baseline",
                marginBottom: 14,
                paddingBottom: 10,
                borderBottom: "2px solid #cbd5e1",
              }}
            >
              <h3
                style={{
                  margin: 0,
                  fontSize: 24,
                }}
              >
                {family.name}
              </h3>

              <span
                style={{
                  color: "#64748b",
                  fontSize: 14,
                }}
              >
                {familyProductsCount} produit(s)
              </span>
            </div>

            {categoriesArray.map(({ category, products: categoryProducts }) => (
              <div
                key={category.id}
                style={{
                  marginBottom: 18,
                  border: "1px solid #e2e8f0",
                  borderRadius: 10,
                  overflow: "hidden",
                  background: "white",
                }}
              >
                {/* Catégorie */}

                <div
                  style={{
                    display: "flex",
                    gap: 10,
                    alignItems: "baseline",
                    padding: "13px 16px",
                    background: "#f1f5f9",
                    borderBottom: "1px solid #e2e8f0",
                  }}
                >
                  <strong>{category.name}</strong>

                  <span
                    style={{
                      color: "#64748b",
                      fontSize: 13,
                    }}
                  >
                    {categoryProducts.length} produit(s)
                  </span>
                </div>

                {/* Produits */}

                <div>
                  {categoryProducts.map((product, index) => (
                    <div
                      key={product.id}
                      style={{
                        display: "grid",

                        gridTemplateColumns:
                          "minmax(220px, 1fr) 120px 120px 110px 140px",

                        gap: 18,
                        alignItems: "center",

                        padding: "13px 16px",

                        borderBottom:
                          index < categoryProducts.length - 1
                            ? "1px solid #e2e8f0"
                            : "none",

                        opacity: product.is_active ? 1 : 0.65,
                      }}
                    >
                      <div>
                        <strong>{product.name}</strong>
                      </div>

                      <div
                        style={{
                          color: "#475569",
                        }}
                      >
                        {product.conservation ?? "—"}
                      </div>

                      <div>
                        <span
                          style={{
                            display: "inline-block",

                            padding: "6px 9px",

                            borderRadius: 8,

                            background: product.is_active
                              ? "#ecfdf5"
                              : "#f1f5f9",

                            color: product.is_active ? "#166534" : "#64748b",

                            fontSize: 13,

                            fontWeight: 600,
                          }}
                        >
                          {product.is_active ? "Actif" : "Inactif"}
                        </span>
                      </div>

                      <div>
                        <button
                          type="button"
                          onClick={() => startEditingProduct(product)}
                          style={{
                            padding: "7px 11px",
                            border: "1px solid #cbd5e1",
                            borderRadius: 8,
                            background: "white",
                            color: "#0f172a",
                            fontWeight: 600,
                            cursor: "pointer",
                          }}
                        >
                          Modifier
                        </button>
                      </div>
                      <div>
                        <button
                          type="button"
                          onClick={() => void toggleProductActive(product)}
                          disabled={updatingProductId === product.id}
                          style={{
                            padding: "7px 11px",
                            border: "1px solid #cbd5e1",
                            borderRadius: 8,

                            background: product.is_active ? "white" : "#0f172a",

                            color: product.is_active ? "#475569" : "white",

                            fontWeight: 600,

                            cursor:
                              updatingProductId === product.id
                                ? "not-allowed"
                                : "pointer",
                          }}
                        >
                          {updatingProductId === product.id
                            ? "..."
                            : product.is_active
                              ? "Désactiver"
                              : "Réactiver"}
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </section>
        );
      })}
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| Compteur
|--------------------------------------------------------------------------
*/

function CounterCard({ label, value }: { label: string; value: number }) {
  return (
    <div
      style={{
        padding: "14px 16px",
        border: "1px solid #e2e8f0",
        borderRadius: 10,
        background: "white",
      }}
    >
      <div
        style={{
          color: "#64748b",
          fontSize: 13,
          marginBottom: 4,
        }}
      >
        {label}
      </div>

      <strong
        style={{
          fontSize: 24,
        }}
      >
        {value}
      </strong>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| Bouton filtre
|--------------------------------------------------------------------------
*/

function FilterButton({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      style={{
        padding: "7px 11px",
        border: 0,
        borderRadius: 7,
        cursor: "pointer",

        background: active ? "white" : "transparent",

        color: active ? "#0f172a" : "#64748b",

        fontWeight: 600,

        boxShadow: active ? "0 1px 2px rgba(15, 23, 42, 0.08)" : "none",
      }}
    >
      {children}
    </button>
  );
}
