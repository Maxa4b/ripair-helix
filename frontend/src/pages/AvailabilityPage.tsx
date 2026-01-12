import type { FormEvent } from "react";
import { useMemo, useState } from "react";
import {
  useAvailabilityBlocks,
  useCreateAvailabilityBlock,
  useDeleteAvailabilityBlock,
} from "../hooks/useAvailability";
import type { AvailabilityBlock } from "../hooks/useAvailability";

function initialRange() {
  const today = new Date();
  const monthStart = new Date(today.getFullYear(), today.getMonth(), 1, 0, 0, 0);
  const monthEnd = new Date(today.getFullYear(), today.getMonth() + 1, 0, 23, 59, 59);
  return {
    start: monthStart.toISOString().slice(0, 16),
    end: monthEnd.toISOString().slice(0, 16),
  };
}

export default function AvailabilityPage() {
  const [range, setRange] = useState(initialRange);
  const [form, setForm] = useState<{
    type: AvailabilityBlock["type"];
    title: string;
    start_datetime: string;
    end_datetime: string;
    notes: string;
  }>(() => ({
    type: "closed",
    title: "",
    start_datetime: initialRange().start,
    end_datetime: initialRange().end,
    notes: "",
  }));

  const queryRange = useMemo(
    () => ({
      start: new Date(range.start).toISOString(),
      end: new Date(range.end).toISOString(),
    }),
    [range],
  );

  const availabilityQuery = useAvailabilityBlocks(queryRange);
  const createMutation = useCreateAvailabilityBlock(queryRange);
  const deleteMutation = useDeleteAvailabilityBlock(queryRange);
  const isCreatingBlock = createMutation.isPending;
  const isDeletingBlock = deleteMutation.isPending;

  const blocks = availabilityQuery.data ?? [];

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    await createMutation.mutateAsync({
      type: form.type,
      title: form.title || undefined,
      start_datetime: new Date(form.start_datetime).toISOString(),
      end_datetime: new Date(form.end_datetime).toISOString(),
      notes: form.notes || undefined,
    });
    setForm((prev) => ({ ...prev, title: "", notes: "" }));
  };

  const handleDelete = async (id: number) => {
    if (!window.confirm("Supprimer ce bloc de disponibilite ?")) return;
    await deleteMutation.mutateAsync(id);
  };

  return (
    <div style={{ display: "grid", gap: 24 }}>
      <header>
        <h2 style={{ margin: 0 }}>Disponibilites</h2>
        <p style={{ margin: 0, color: "#64748b" }}>
          Cree des blocs ouverts ou fermes qui apparaissent dans l'agenda.
        </p>
      </header>

      <section
        style={{
          background: "#fff",
          borderRadius: 14,
          padding: 20,
          boxShadow: "0 8px 24px rgba(15,23,42,0.08)",
        }}
      >
        <h3 style={{ marginTop: 0 }}>Ajouter un bloc</h3>
        <form
          onSubmit={handleSubmit}
          style={{ display: "grid", gap: 12, gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))" }}
        >
          <label style={labelStyle}>
            Type
            <select
              value={form.type}
              onChange={(event) =>
                setForm((prev) => ({
                  ...prev,
                  type: event.target.value as AvailabilityBlock["type"],
                }))
              }
              style={inputStyle}
            >
              <option value="open">Ouvert</option>
              <option value="closed">Ferme</option>
              <option value="maintenance">Maintenance</option>
              <option value="offsite">Deplacement</option>
            </select>
          </label>

          <label style={labelStyle}>
            Titre
            <input
              value={form.title}
              onChange={(event) => setForm((prev) => ({ ...prev, title: event.target.value }))}
              placeholder="Ex : Fermeture exceptionnelle"
              style={inputStyle}
            />
          </label>

          <label style={labelStyle}>
            Debut
            <input
              type="datetime-local"
              value={form.start_datetime}
              onChange={(event) => setForm((prev) => ({ ...prev, start_datetime: event.target.value }))}
              style={inputStyle}
              required
            />
          </label>

          <label style={labelStyle}>
            Fin
            <input
              type="datetime-local"
              value={form.end_datetime}
              onChange={(event) => setForm((prev) => ({ ...prev, end_datetime: event.target.value }))}
              style={inputStyle}
              required
            />
          </label>

          <label style={{ ...labelStyle, gridColumn: "1 / -1" }}>
            Notes
            <textarea
              value={form.notes}
              onChange={(event) => setForm((prev) => ({ ...prev, notes: event.target.value }))}
              style={textareaStyle}
              placeholder="Informations internes, contraintes, etc."
            />
          </label>

          <div style={{ gridColumn: "1 / -1", display: "flex", justifyContent: "flex-end" }}>
            <button type="submit" style={buttonStyle} disabled={isCreatingBlock}>
              {isCreatingBlock ? "Creation..." : "Enregistrer"}
            </button>
          </div>
        </form>
      </section>

      <section
        style={{
          background: "#fff",
          borderRadius: 14,
          padding: 20,
          boxShadow: "0 8px 24px rgba(15,23,42,0.08)",
          display: "grid",
          gap: 18,
        }}
      >
        <header style={{ display: "flex", flexWrap: "wrap", gap: 12, alignItems: "baseline" }}>
          <h3 style={{ margin: 0 }}>Calendrier</h3>
          <div style={{ display: "flex", gap: 12 }}>
            <label style={{ ...labelStyle, gap: 4 }}>
              Debut intervalle
              <input
                type="datetime-local"
                value={range.start}
                onChange={(event) => setRange((prev) => ({ ...prev, start: event.target.value }))}
                style={inputStyle}
              />
            </label>
            <label style={{ ...labelStyle, gap: 4 }}>
              Fin intervalle
              <input
                type="datetime-local"
                value={range.end}
                onChange={(event) => setRange((prev) => ({ ...prev, end: event.target.value }))}
                style={inputStyle}
              />
            </label>
          </div>
        </header>

        {availabilityQuery.isFetching && <p>Mise a jour des plages.</p>}
        {availabilityQuery.error && (
          <p style={{ color: "#e53e3e" }}>Impossible de charger les disponibilites.</p>
        )}

        <div style={{ display: "grid", gap: 14 }}>
          {blocks.length === 0 && <p>Aucun bloc dans l'intervalle selectionne.</p>}
          {blocks.map((block) => (
            <article
              key={block.id}
              style={{
                background: "#fff",
                borderRadius: 12,
                padding: "14px 18px",
                boxShadow: "0 8px 20px rgba(15,23,42,0.07)",
                borderLeft: `6px solid ${block.color ?? colorForType(block.type)}`,
                display: "grid",
                gap: 6,
              }}
            >
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                <div>
                  <strong>{block.title || block.type}</strong>
                  <span style={{ marginLeft: 8, ...badgeStyle(block.type) }}>{block.type}</span>
                </div>
                <button
                  type="button"
                  onClick={() => handleDelete(block.id)}
                  style={{ ...buttonStyle, background: "#ef4444" }}
                  disabled={isDeletingBlock}
                >
                  Supprimer
                </button>
              </div>
              <div style={{ color: "#475569", fontSize: 14 }}>
                {formatDate(block.start_datetime)} - {formatDate(block.end_datetime)}
              </div>
              {block.notes && <p style={{ margin: 0, color: "#334155" }}>{block.notes}</p>}
            </article>
          ))}
        </div>
      </section>
    </div>
  );
}

function formatDate(iso: string) {
  return new Date(iso).toLocaleString("fr-FR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function colorForType(type: string) {
  switch (type) {
    case "open":
      return "#16a34a";
    case "closed":
      return "#ef4444";
    case "maintenance":
      return "#f59e0b";
    case "offsite":
      return "#3b82f6";
    default:
      return "#6366f1";
  }
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

const textareaStyle: React.CSSProperties = {
  borderRadius: 8,
  border: "1px solid #cbd5f5",
  padding: "10px 12px",
  fontSize: 14,
  background: "#fff",
  resize: "vertical",
};

const buttonStyle: React.CSSProperties = {
  borderRadius: 8,
  border: "none",
  padding: "10px 16px",
  background: "#2563eb",
  color: "#fff",
  fontWeight: 600,
  cursor: "pointer",
};

const badgeStyle = (type: string): React.CSSProperties => ({
  display: "inline-flex",
  alignItems: "center",
  padding: "2px 8px",
  borderRadius: 999,
  fontSize: 12,
  marginLeft: 6,
  background: `${colorForType(type)}22`,
  color: colorForType(type),
  textTransform: "uppercase",
});
