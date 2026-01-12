import axios from "axios";
import { useMemo, useState } from "react";
import {
  useCreateUser,
  useDeleteUser,
  useHelixUsers,
  useUpdateUser,
  type HelixUser,
} from "../hooks/useUsers";

type FilterState = "all" | "active" | "inactive";

type Draft = {
  phone: string;
  color: string;
};

const defaultColor = "#2563eb";

export default function TechniciansPage() {
  const [filter, setFilter] = useState<FilterState>("active");
  const [createOpen, setCreateOpen] = useState(false);
  const [feedback, setFeedback] = useState("");
  const [createForm, setCreateForm] = useState({
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    color: defaultColor,
    password: "",
  });
  const [drafts, setDrafts] = useState<Record<number, Draft>>({});

  const filters = useMemo(() => {
    const base: { role: string; isActive?: boolean } = { role: "technician" };
    if (filter !== "all") {
      base.isActive = filter === "active";
    }
    return base;
  }, [filter]);

  const techniciansQuery = useHelixUsers(filters);
  const technicians = useMemo(() => techniciansQuery.data ?? [], [techniciansQuery.data]);

  const createMutation = useCreateUser();
  const updateMutation = useUpdateUser();
  const deleteMutation = useDeleteUser();

  const activeCount = useMemo(() => technicians.filter((tech) => tech.is_active).length, [technicians]);
  const inactiveCount = technicians.length - activeCount;

  const showFeedback = (message: string) => setFeedback(message);
  const clearFeedback = () => setFeedback("");

  const getDraft = (user: HelixUser): Draft => {
    if (drafts[user.id]) {
      return drafts[user.id];
    }
    return {
      phone: user.phone ?? "",
      color: user.color ?? defaultColor,
    };
  };

  const setDraft = (userId: number, draft: Draft) => {
    setDrafts((prev) => ({ ...prev, [userId]: draft }));
  };

  const resetDraft = (userId: number) => {
    setDrafts((prev) => {
      const next = { ...prev };
      delete next[userId];
      return next;
    });
  };

  const handleCreate = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!createForm.first_name || !createForm.last_name || !createForm.email || !createForm.password) {
      window.alert("Merci de renseigner prénom, nom, email et mot de passe.");
      return;
    }

    try {
      await createMutation.mutateAsync({
        ...createForm,
        phone: createForm.phone || null,
        color: createForm.color || null,
        role: "technician",
        is_active: true,
      });
      showFeedback(`Technicien ajouté : ${createForm.first_name} ${createForm.last_name}`);
      setCreateForm({ first_name: "", last_name: "", email: "", phone: "", color: defaultColor, password: "" });
      setCreateOpen(false);
    } catch (error) {
      console.error(error);
      if (axios.isAxiosError(error)) {
        const messages =
          Object.values<string | string[]>(error.response?.data?.errors ?? {})
            .flat()
            .join("\n") || error.response?.data?.message;
        window.alert(messages ?? "Création impossible. Vérifie les informations.");
      } else {
        window.alert("Création impossible. Vérifie les informations.");
      }
    }
  };

  const handleSave = async (user: HelixUser) => {
    const draft = getDraft(user);
    const dirty = draft.phone !== (user.phone ?? "") || draft.color !== (user.color ?? defaultColor);

    if (!dirty) {
      showFeedback("Aucun changement à enregistrer.");
      return;
    }

    try {
      await updateMutation.mutateAsync({
        id: user.id,
        payload: {
          phone: draft.phone || null,
          color: draft.color || null,
        },
      });
      showFeedback(`Profil mis à jour pour ${user.full_name}`);
      resetDraft(user.id);
    } catch (error) {
      console.error(error);
      window.alert("Modification impossible.");
    }
  };

  const handleToggleActive = async (user: HelixUser) => {
    const nextState = !user.is_active;
    if (
      !window.confirm(
        nextState
          ? `Réactiver ${user.full_name} ?`
          : `Désactiver ${user.full_name} ? Il ne pourra plus recevoir de nouveaux rendez-vous.`,
      )
    ) {
      return;
    }

    try {
      await updateMutation.mutateAsync({
        id: user.id,
        payload: { is_active: nextState },
      });
      showFeedback(
        nextState ? `${user.full_name} est maintenant actif.` : `${user.full_name} est désactivé.`,
      );
    } catch (error) {
      console.error(error);
      window.alert("Impossible de changer le statut.");
    }
  };

  const handleDelete = async (user: HelixUser) => {
    if (
      !window.confirm(
        `Supprimer définitivement ${user.full_name} ?\nSon email pourra être réutilisé pour un nouveau compte.`,
      )
    ) {
      return;
    }

    try {
      await deleteMutation.mutateAsync(user.id);
      showFeedback(`${user.full_name} a été supprimé.`);
      resetDraft(user.id);
    } catch (error) {
      console.error(error);
      window.alert("Suppression impossible.");
    }
  };

  const filteredTechnicians = useMemo(() => {
    if (filter === "all") {
      return technicians;
    }
    const target = filter === "active";
    return technicians.filter((tech) => tech.is_active === target);
  }, [filter, technicians]);

  return (
    <div style={{ display: "grid", gap: 24 }}>
      <header>
        <h2 style={{ margin: 0 }}>Techniciens</h2>
        <p style={{ margin: 0, color: "#64748b" }}>
          Gère l’équipe, invite de nouveaux techniciens et ajuste leurs informations.
        </p>
      </header>

      {feedback && (
        <div style={feedbackStyle}>
          <span>{feedback}</span>
          <button type="button" onClick={clearFeedback} style={feedbackButton}>
            OK
          </button>
        </div>
      )}

      <section style={sectionStyle}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <h3 style={{ margin: 0 }}>Ajouter un technicien</h3>
          <button
            type="button"
            onClick={() => setCreateOpen((prev) => !prev)}
            style={{
              ...secondaryButton,
              background: createOpen ? "#fee2e2" : "#2563eb",
              color: createOpen ? "#b91c1c" : "#fff",
            }}
            disabled={createMutation.isPending}
          >
            {createOpen ? "Fermer" : "+ Nouveau technicien"}
          </button>
        </div>

        {createOpen && (
          <form
            onSubmit={handleCreate}
            style={{
              marginTop: 16,
              display: "grid",
              gap: 14,
              gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))",
            }}
          >
            <label style={labelStyle}>
              Prénom
              <input
                value={createForm.first_name}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, first_name: event.target.value }))}
                style={inputStyle}
                required
              />
            </label>
            <label style={labelStyle}>
              Nom
              <input
                value={createForm.last_name}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, last_name: event.target.value }))}
                style={inputStyle}
                required
              />
            </label>
            <label style={labelStyle}>
              Email
              <input
                type="email"
                value={createForm.email}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, email: event.target.value }))}
                style={inputStyle}
                required
              />
            </label>
            <label style={labelStyle}>
              Téléphone
              <input
                value={createForm.phone}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, phone: event.target.value }))}
                style={inputStyle}
                placeholder="Optionnel"
              />
            </label>
            <label style={labelStyle}>
              Couleur agenda
              <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                <input
                  type="color"
                  value={createForm.color}
                  onChange={(event) => setCreateForm((prev) => ({ ...prev, color: event.target.value }))}
                  style={{ ...inputStyle, padding: 4, width: 56 }}
                />
                <code style={{ color: "#475569", fontSize: 13 }}>{createForm.color}</code>
              </div>
            </label>
            <label style={labelStyle}>
              Mot de passe
              <input
                type="password"
                value={createForm.password}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, password: event.target.value }))}
                style={inputStyle}
                required
                minLength={8}
              />
            </label>

            <div style={{ gridColumn: "1 / -1", display: "flex", justifyContent: "flex-end", gap: 12 }}>
              <button
                type="button"
                onClick={() => setCreateForm({ first_name: "", last_name: "", email: "", phone: "", color: defaultColor, password: "" })}
                style={secondaryButton}
                disabled={createMutation.isPending}
              >
                Effacer
              </button>
              <button type="submit" style={primaryButton} disabled={createMutation.isPending}>
                {createMutation.isPending ? "Création…" : "Ajouter"}
              </button>
            </div>
          </form>
        )}
      </section>

      <section style={sectionStyle}>
        <header style={{ display: "flex", flexWrap: "wrap", gap: 12, alignItems: "center" }}>
          <h3 style={{ margin: 0 }}>Équipe</h3>
          <div style={{ display: "flex", gap: 8 }}>
            <FilterChip active={filter === "active"} onClick={() => setFilter("active")}>
              Actifs ({activeCount})
            </FilterChip>
            <FilterChip active={filter === "inactive"} onClick={() => setFilter("inactive")}>
              Inactifs ({inactiveCount})
            </FilterChip>
            <FilterChip active={filter === "all"} onClick={() => setFilter("all")}>
              Tous ({technicians.length})
            </FilterChip>
          </div>
        </header>

        {techniciansQuery.isPending && <p>Chargement des techniciens…</p>}
        {techniciansQuery.error && (
          <p style={{ color: "#e53e3e" }}>Impossible de récupérer la liste des techniciens.</p>
        )}
        {!techniciansQuery.isPending && filteredTechnicians.length === 0 && (
          <p>Aucun technicien pour ce filtre.</p>
        )}

        {filteredTechnicians.length > 0 && (
          <div style={cardGrid}>
            {filteredTechnicians.map((technician) => {
              const draft = getDraft(technician);
              const dirty =
                draft.phone !== (technician.phone ?? "") || draft.color !== (technician.color ?? defaultColor);
              const lastLogin = formatDate(technician.last_login_at);

              return (
                <article
                  key={technician.id}
                  style={{
                    ...cardStyle,
                    opacity: technician.is_active ? 1 : 0.6,
                  }}
                >
                  <header style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                    <div style={{ display: "grid", gap: 4 }}>
                      <strong>{technician.full_name}</strong>
                      <span style={{ color: "#64748b", fontSize: 13 }}>{technician.email}</span>
                      <span style={{ color: "#94a3b8", fontSize: 12 }}>Dernière connexion : {lastLogin}</span>
                    </div>
                    <span
                      style={{
                        width: 18,
                        height: 18,
                        borderRadius: "50%",
                        border: "1px solid #cbd5f5",
                        background: draft.color || defaultColor,
                        display: "inline-flex",
                      }}
                    />
                  </header>

                  <div style={{ display: "grid", gap: 12 }}>
                    <label style={labelStyle}>
                      Téléphone
                      <input
                        value={draft.phone}
                        onChange={(event) => setDraft(technician.id, { ...draft, phone: event.target.value })}
                        style={inputStyle}
                        placeholder="Numéro"
                      />
                    </label>

                    <label style={labelStyle}>
                      Couleur agenda
                      <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                        <input
                          type="color"
                          value={draft.color}
                          onChange={(event) => setDraft(technician.id, { ...draft, color: event.target.value })}
                          style={{ ...inputStyle, padding: 2, width: 56 }}
                        />
                        <code style={{ color: "#475569", fontSize: 13 }}>{draft.color}</code>
                      </div>
                    </label>
                  </div>

                  <footer style={cardFooterStyle}>
                    <span
                      style={{
                        display: "inline-flex",
                        alignItems: "center",
                        padding: "2px 10px",
                        borderRadius: 999,
                        fontSize: 12,
                        background: technician.is_active ? "#dcfce7" : "#fee2e2",
                        color: technician.is_active ? "#15803d" : "#b91c1c",
                      }}
                    >
                      {technician.is_active ? "Actif" : "Inactif"}
                    </span>

                    <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                      {dirty && (
                        <button
                          type="button"
                          onClick={() => resetDraft(technician.id)}
                          style={ghostButton}
                          disabled={updateMutation.isPending}
                        >
                          Annuler
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => handleSave(technician)}
                        style={{
                          ...primaryButton,
                          background: dirty ? "#2563eb" : "#94a3b8",
                          cursor: dirty ? "pointer" : "not-allowed",
                        }}
                        disabled={updateMutation.isPending || !dirty}
                      >
                        Enregistrer
                      </button>
                      <button
                        type="button"
                        onClick={() => handleToggleActive(technician)}
                        style={{
                          ...secondaryButton,
                          background: technician.is_active ? "#fee2e2" : "#dcfce7",
                          color: technician.is_active ? "#b91c1c" : "#15803d",
                        }}
                        disabled={updateMutation.isPending}
                      >
                        {technician.is_active ? "Désactiver" : "Réactiver"}
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDelete(technician)}
                        style={{ ...secondaryButton, background: "#f87171", color: "#fff" }}
                        disabled={deleteMutation.isPending}
                      >
                        Supprimer
                      </button>
                    </div>
                  </footer>
                </article>
              );
            })}
          </div>
        )}
      </section>
    </div>
  );
}

function FilterChip({ children, active, onClick }: { children: React.ReactNode; active: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      style={{
        padding: "6px 12px",
        borderRadius: 999,
        border: "none",
        cursor: "pointer",
        background: active ? "#c7d2fe" : "#e2e8f0",
        color: active ? "#1e3a8a" : "#334155",
        fontSize: 13,
      }}
    >
      {children}
    </button>
  );
}

const labelStyle: React.CSSProperties = {
  display: "grid",
  gap: 6,
  fontSize: 14,
  color: "#475569",
};

const inputStyle: React.CSSProperties = {
  borderRadius: 8,
  border: "1px solid #cbd5f5",
  padding: "8px 12px",
  fontSize: 14,
  background: "#fff",
};

const primaryButton: React.CSSProperties = {
  border: "none",
  borderRadius: 8,
  padding: "10px 16px",
  background: "#2563eb",
  color: "#fff",
  fontWeight: 600,
  cursor: "pointer",
};

const secondaryButton: React.CSSProperties = {
  border: "none",
  borderRadius: 8,
  padding: "8px 12px",
  background: "#e2e8f0",
  color: "#1e293b",
  cursor: "pointer",
  fontWeight: 600,
};

const ghostButton: React.CSSProperties = {
  border: "1px solid #cbd5f5",
  borderRadius: 8,
  padding: "8px 12px",
  background: "#fff",
  color: "#1e293b",
  cursor: "pointer",
  fontWeight: 600,
};

const sectionStyle: React.CSSProperties = {
  background: "#fff",
  borderRadius: 12,
  padding: 20,
  boxShadow: "0 12px 32px rgba(15,23,42,0.07)",
  display: "grid",
  gap: 16,
};

const cardGrid: React.CSSProperties = {
  display: "grid",
  gap: 16,
  gridTemplateColumns: "repeat(auto-fit, minmax(260px, 1fr))",
};

const cardStyle: React.CSSProperties = {
  display: "grid",
  gap: 16,
  padding: 18,
  borderRadius: 14,
  border: "1px solid #e2e8f0",
  background: "#fff",
  boxShadow: "0 10px 24px rgba(15,23,42,0.08)",
};

const cardFooterStyle: React.CSSProperties = {
  display: "flex",
  justifyContent: "space-between",
  alignItems: "center",
  gap: 12,
  flexWrap: "wrap",
};

const feedbackStyle: React.CSSProperties = {
  padding: "10px 14px",
  borderRadius: 10,
  background: "#ecfdf5",
  border: "1px solid #bbf7d0",
  color: "#047857",
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  gap: 12,
};

const feedbackButton: React.CSSProperties = {
  border: "none",
  borderRadius: 6,
  padding: "6px 10px",
  background: "#047857",
  color: "#fff",
  cursor: "pointer",
  fontWeight: 600,
};

function formatDate(iso?: string | null): string {
  if (!iso) {
    return "Jamais connecté";
  }
  return new Date(iso).toLocaleString("fr-FR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
