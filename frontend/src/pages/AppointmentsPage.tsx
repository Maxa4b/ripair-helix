import type { FormEvent } from "react";
import { useMemo, useState } from "react";
import { useAppointments, useUpdateAppointment, type Appointment } from "../hooks/useAppointments";
import { useHelixUsers } from "../hooks/useUsers";

const STATUSES = ["all", "booked", "confirmed", "in_progress", "done", "cancelled", "no_show"] as const;

export default function AppointmentsPage() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<(typeof STATUSES)[number]>("all");
  const [search, setSearch] = useState("");
  const [assignedUserFilter, setAssignedUserFilter] = useState<"all" | number>("all");

  const techniciansQuery = useHelixUsers({ role: "technician", isActive: true });
  const technicians = techniciansQuery.data ?? [];

  const appointmentsQuery = useAppointments({
    page,
    perPage: 10,
    status,
    search,
    assignedUserId: assignedUserFilter === "all" ? undefined : assignedUserFilter,
  });
  const { data, error, isPending } = appointmentsQuery;
  const updateAppointment = useUpdateAppointment();
  const isUpdatingAppointment = updateAppointment.isPending;

  const rows = data?.data ?? [];
  const meta = data?.meta;

  const totalPages = useMemo(() => {
    if (!meta) return 1;
    return meta.last_page;
  }, [meta]);

  const handleStatusChange = (id: number, newStatus: string) => {
    updateAppointment.mutate({ id, payload: { status: newStatus } });
  };

  const handleAssigneeChange = (id: number, value: string) => {
    const assignedId = value === "" ? null : Number(value);
    updateAppointment.mutate({ id, payload: { assigned_user_id: assignedId } });
  };

  const handleSearchSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setPage(1);
  };

  return (
    <div style={{ display: "grid", gap: 24 }}>
      <header style={{ display: "flex", flexWrap: "wrap", gap: 16, justifyContent: "space-between" }}>
        <div>
          <h2 style={{ margin: 0 }}>Rendez-vous</h2>
          <p style={{ margin: 0, color: "#64748b" }}>
            Visualise, recherche et mets a jour les rendez-vous.
          </p>
        </div>

        <form onSubmit={handleSearchSubmit} style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <input
            type="search"
            placeholder="Rechercher (nom, email, telephone)."
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            style={searchInputStyle}
          />
          <button type="submit" style={buttonStyle}>
            Rechercher
          </button>
        </form>
      </header>

      <div style={{ display: "flex", flexWrap: "wrap", gap: 12, alignItems: "center" }}>
        <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
          {STATUSES.map((value) => (
            <button
              key={value}
              type="button"
              onClick={() => {
                setStatus(value);
                setPage(1);
              }}
              style={{
                ...filterButtonStyle,
                background: status === value ? "#c7d2fe" : "#e2e8f0",
                color: status === value ? "#1d4ed8" : "#334155",
              }}
            >
              {value === "all" ? "Tous" : value}
            </button>
          ))}
        </div>

        <label style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 14, color: "#475569" }}>
          Technicien
          <select
            value={assignedUserFilter}
            onChange={(event) => {
              const value = event.target.value === "all" ? "all" : Number(event.target.value);
              setAssignedUserFilter(value);
              setPage(1);
            }}
            style={selectStyle}
          >
            <option value="all">Tous</option>
            {technicians.map((technician) => (
              <option key={technician.id} value={technician.id}>
                {technician.full_name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {isPending && <p>Chargement des rendez-vous.</p>}
      {error && <p style={{ color: "#e53e3e" }}>Impossible de charger les rendez-vous. Verifie la connexion API.</p>}

      {!isPending && rows.length === 0 && <p>Aucun rendez-vous enregistre.</p>}

      {rows.length > 0 && (
        <div style={{ overflowX: "auto" }}>
          <table style={tableStyle}>
            <thead>
              <tr>
                <th>ID</th>
                <th>Service</th>
                <th>Client</th>
                <th>Date</th>
                <th>Statut</th>
                <th>Montant</th>
                <th>Assigne</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((appointment: Appointment) => (
                <tr key={appointment.id}>
                  <td>{appointment.id}</td>
                  <td>{appointment.service_label}</td>
                  <td>
                    <div>{appointment.customer?.name ?? "N/A"}</div>
                    <small style={{ color: "#64748b" }}>{appointment.customer?.email ?? ""}</small>
                  </td>
                  <td>
                    {new Date(appointment.start_datetime).toLocaleString("fr-FR", {
                      day: "2-digit",
                      month: "2-digit",
                      year: "numeric",
                      hour: "2-digit",
                      minute: "2-digit",
                    })}
                  </td>
                  <td>
                    <select
                      value={appointment.status}
                      onChange={(event) => handleStatusChange(appointment.id, event.target.value)}
                      style={selectStyle}
                      disabled={isUpdatingAppointment}
                    >
                      {STATUSES.filter((s) => s !== "all").map((statusOption) => (
                        <option value={statusOption} key={statusOption}>
                          {statusOption}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td>
                    {typeof appointment.price_estimate_cents === "number"
                      ? `${(appointment.price_estimate_cents / 100).toLocaleString("fr-FR", {
                          style: "currency",
                          currency: "EUR",
                        })}`
                      : "N/A"}
                  </td>
                  <td>
                    <select
                      value={appointment.assigned_user?.id?.toString() ?? ""}
                      onChange={(event) => handleAssigneeChange(appointment.id, event.target.value)}
                      style={selectStyle}
                      disabled={isUpdatingAppointment}
                    >
                      <option value="">Non assigne</option>
                      {technicians.map((technician) => (
                        <option key={technician.id} value={technician.id}>
                          {technician.full_name}
                        </option>
                      ))}
                    </select>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta && (
        <div style={{ display: "flex", gap: 12, alignItems: "center", justifyContent: "flex-end" }}>
          <span>
            Page {meta.current_page} / {meta.last_page}
          </span>
          <button
            type="button"
            onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
            disabled={page <= 1}
            style={buttonStyle}
          >
            &larr; Precedent
          </button>
          <button
            type="button"
            onClick={() => setPage((prev) => Math.min(prev + 1, totalPages))}
            disabled={page >= totalPages}
            style={buttonStyle}
          >
            Suivant &rarr;
          </button>
        </div>
      )}
    </div>
  );
}

const tableStyle: React.CSSProperties = {
  width: "100%",
  borderCollapse: "collapse",
  background: "#fff",
  borderRadius: 12,
  overflow: "hidden",
  boxShadow: "0 12px 32px rgba(15,23,42,0.07)",
};

const searchInputStyle: React.CSSProperties = {
  borderRadius: 8,
  border: "1px solid #cbd5f5",
  padding: "8px 12px",
  fontSize: 14,
  background: "#fff",
  minWidth: 220,
};

const buttonStyle: React.CSSProperties = {
  border: "none",
  borderRadius: 8,
  padding: "8px 14px",
  background: "#2563eb",
  color: "#fff",
  cursor: "pointer",
  fontWeight: 600,
};

const filterButtonStyle: React.CSSProperties = {
  padding: "6px 12px",
  borderRadius: 999,
  border: "none",
  cursor: "pointer",
  background: "#e2e8f0",
  fontSize: 13,
  textTransform: "capitalize",
};

const selectStyle: React.CSSProperties = {
  borderRadius: 8,
  border: "1px solid #cbd5f5",
  padding: "6px 10px",
  background: "#fff",
};
