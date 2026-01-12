import { useMemo, useState } from 'react';
import type { ScheduleDay, ScheduleSlot, ScheduleSlotStatus } from '../hooks/useSchedule';
import { useScheduleSlots, useToggleSlot } from '../hooks/useSchedule';

const DAYS_IN_VIEW = 7;

export default function CalendarPage() {
  const [anchorDate, setAnchorDate] = useState(() => startOfWeek(new Date()));

  const queryParams = useMemo(
    () => ({
      start: formatDateParam(anchorDate),
      days: DAYS_IN_VIEW,
      duration_min: 60,
    }),
    [anchorDate],
  );

  const scheduleQuery = useScheduleSlots(queryParams);
  const toggleMutation = useToggleSlot(queryParams);

  const days: ScheduleDay[] = scheduleQuery.data ?? [];
  const isLoading = scheduleQuery.isPending || scheduleQuery.isFetching;
  const hasError = Boolean(scheduleQuery.error);
  const pendingKey =
    toggleMutation.isPending && toggleMutation.variables
      ? `${toggleMutation.variables.start}|${toggleMutation.variables.end}`
      : null;

  const handleChangeWeek = (direction: 'prev' | 'next' | 'today') => {
    if (direction === 'today') {
      setAnchorDate(startOfWeek(new Date()));
      return;
    }

    setAnchorDate((current) => addDays(current, direction === 'next' ? DAYS_IN_VIEW : -DAYS_IN_VIEW));
  };

  const handleToggle = async (slot: ScheduleSlot) => {
    if (!slot.toggleable || toggleMutation.isPending) {
      return;
    }

    const endParam = slot.toggle_end ?? slot.end;
    await toggleMutation.mutateAsync({
      start: slot.start,
      end: endParam,
      makeAvailable: slot.status === 'closed',
      blockId: slot.block_id ?? undefined,
      stepMinutes: slot.step_minutes ?? undefined,
    });
  };

  return (
    <div style={{ display: 'grid', gap: 24 }}>
      <header style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <h2 style={{ margin: 0 }}>Agenda</h2>
        <p style={{ margin: 0, color: '#64748b', maxWidth: 640 }}>
          Clique sur les créneaux pour les activer ou les désactiver. Les rendez-vous existants et les plages
          passées ne peuvent pas être modifiés.
        </p>
      </header>

      <section
        style={{
          background: '#fff',
          borderRadius: 16,
          padding: 24,
          boxShadow: '0 16px 32px rgba(15,23,42,0.08)',
          display: 'grid',
          gap: 24,
        }}
      >
        <div
          style={{
            display: 'flex',
            flexWrap: 'wrap',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 12,
          }}
        >
          <div>
            <strong style={{ fontSize: 18 }}>{formatRangeLabel(anchorDate)}</strong>
            <div style={{ color: '#64748b', fontSize: 14 }}>
              {isLoading ? 'Chargement des créneaux...' : `${days.length} jour(s) affichés`}
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" onClick={() => handleChangeWeek('prev')} style={navButtonStyle}>
              &#8592; Précédent
            </button>
            <button type="button" onClick={() => handleChangeWeek('today')} style={navButtonStyle}>
              Aujourd&apos;hui
            </button>
            <button type="button" onClick={() => handleChangeWeek('next')} style={navButtonStyle}>
              Suivant &#8594;
            </button>
          </div>
        </div>

        {hasError && (
          <div
            style={{
              padding: '14px 16px',
              borderRadius: 12,
              background: '#fee2e2',
              border: '1px solid #fecaca',
              color: '#b91c1c',
            }}
          >
            Impossible de charger les créneaux. Réessaie dans quelques instants.
          </div>
        )}

        <Legend />

        <div style={dayGridStyle}>
          {days.map((day) => {
            const date = parseDate(day.date);
            const slots = day.slots ?? [];
            return (
              <article key={day.date} style={dayColumnStyle}>
                <header style={dayHeaderStyle}>
                  <span style={{ fontSize: 13, color: '#64748b', textTransform: 'uppercase' }}>
                    {formatDayName(date)}
                  </span>
                  <span style={{ fontSize: 17, fontWeight: 600 }}>{formatDayNumber(date)}</span>
                </header>

                <div style={{ display: 'grid', gap: 8 }}>
                  {slots.length === 0 && (
                    <p style={{ fontSize: 13, color: '#94a3b8', margin: 0 }}>
                      Aucun créneau disponible sur cette journée.
                    </p>
                  )}

                  {slots.map((slot) => {
                    const targetEnd = slot.toggle_end ?? slot.end;
                    const key = `${slot.start}|${targetEnd}`;
                    const status = slotStatus(slot, pendingKey === key);
                    return (
                      <button
                        key={key}
                        type="button"
                        onClick={() => handleToggle(slot)}
                        disabled={!status.isInteractive}
                        style={{
                          ...slotButtonStyle,
                          ...status.style,
                          cursor: status.isInteractive ? 'pointer' : 'not-allowed',
                        }}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                          <span style={{ fontWeight: 600 }}>{slot.time}</span>
                          {slot.discount > 0 && (
                            <span style={discountBadgeStyle}>-{slot.discount}%</span>
                          )}
                        </div>
                        <span style={{ fontSize: 12, color: status.captionColor }}>{status.caption}</span>
                      </button>
                    );
                  })}
                </div>
              </article>
            );
          })}
        </div>
      </section>
    </div>
  );
}

function startOfWeek(date: Date) {
  const result = new Date(date);
  const day = result.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  result.setHours(0, 0, 0, 0);
  result.setDate(result.getDate() + diff);
  return result;
}

function addDays(date: Date, amount: number) {
  const result = new Date(date);
  result.setDate(result.getDate() + amount);
  return result;
}

function formatDateParam(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function formatRangeLabel(startDate: Date) {
  const endDate = addDays(startDate, DAYS_IN_VIEW - 1);
  const startLabel = startDate.toLocaleDateString('fr-FR', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
  });
  const endLabel = endDate.toLocaleDateString('fr-FR', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
  });
  return `${capitalize(startLabel)} \u2192 ${capitalize(endLabel)}`;
}

function parseDate(date: string) {
  return new Date(`${date}T00:00:00`);
}

function formatDayName(date: Date) {
  return capitalize(
    date.toLocaleDateString('fr-FR', {
      weekday: 'short',
    }),
  );
}

function formatDayNumber(date: Date) {
  return date.toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
  });
}

function capitalize(value: string) {
  if (!value) {
    return value;
  }
  return value.charAt(0).toUpperCase() + value.slice(1);
}

type SlotStatus = {
  style: React.CSSProperties;
  caption: string;
  captionColor: string;
  isInteractive: boolean;
};

function slotStatus(slot: ScheduleSlot, isPending: boolean): SlotStatus {
  if (isPending) {
    return {
      style: {
        background: '#dbeafe',
        borderColor: '#2563eb',
        color: '#1d4ed8',
      },
      caption: 'Mise à jour...',
      captionColor: '#1d4ed8',
      isInteractive: false,
    };
  }

  switch (slot.status as ScheduleSlotStatus) {
    case 'available':
      return {
        style: {
          background: '#ecfdf5',
          borderColor: '#34d399',
          color: '#047857',
        },
        caption: slot.toggleable ? 'Cliquer pour fermer' : 'Disponible',
        captionColor: '#0f766e',
        isInteractive: slot.toggleable,
      };
    case 'closed':
      return {
        style: {
          background: slot.toggleable ? '#fee2e2' : '#f3f4f6',
          borderColor: slot.toggleable ? '#f87171' : '#cbd5f5',
          color: slot.toggleable ? '#b91c1c' : '#64748b',
        },
        caption: slot.toggleable ? 'Cliquer pour rouvrir' : 'Bloqué par un bloc',
        captionColor: slot.toggleable ? '#b91c1c' : '#94a3b8',
        isInteractive: slot.toggleable,
      };
    case 'booked':
      return {
        style: {
          background: '#f1f5f9',
          borderColor: '#cbd5f5',
          color: '#475569',
        },
        caption: 'Réservé',
        captionColor: '#475569',
        isInteractive: false,
      };
    case 'past':
    default:
      return {
        style: {
          background: '#e2e8f0',
          borderColor: '#cbd5f5',
          color: '#94a3b8',
        },
        caption: 'Passé',
        captionColor: '#94a3b8',
        isInteractive: false,
      };
  }
}

function Legend() {
  const items: Array<{ label: string; color: string; description: string }> = [
    { label: 'Disponible', color: '#34d399', description: 'Le créneau peut être réservé.' },
    { label: 'Indisponible', color: '#f87171', description: 'Créneau fermé par Helix.' },
    { label: 'Réservé', color: '#475569', description: 'Rendez-vous confirmé.' },
    { label: 'Passé', color: '#94a3b8', description: 'Temps déjà écoulé.' },
  ];

  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 16 }}>
      {items.map((item) => (
        <div key={item.label} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span
            style={{
              width: 12,
              height: 12,
              borderRadius: 999,
              background: item.color,
              display: 'inline-block',
            }}
          />
          <span style={{ fontSize: 13, color: '#475569' }}>
            <strong style={{ marginRight: 4 }}>{item.label}</strong>
            {item.description}
          </span>
        </div>
      ))}
    </div>
  );
}

const navButtonStyle: React.CSSProperties = {
  borderRadius: 999,
  background: '#2563eb',
  color: '#fff',
  border: 'none',
  padding: '8px 16px',
  fontWeight: 600,
  fontSize: 14,
  cursor: 'pointer',
  boxShadow: '0 6px 16px rgba(37,99,235,0.25)',
};

const dayGridStyle: React.CSSProperties = {
  display: 'grid',
  gap: 20,
  gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
};

const dayColumnStyle: React.CSSProperties = {
  display: 'grid',
  gap: 12,
  background: '#f8fafc',
  borderRadius: 14,
  padding: 16,
  border: '1px solid #e2e8f0',
  minHeight: 220,
};

const dayHeaderStyle: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: 2,
};

const slotButtonStyle: React.CSSProperties = {
  borderRadius: 12,
  border: '1px solid transparent',
  background: '#f1f5f9',
  color: '#0f172a',
  padding: '10px 12px',
  display: 'grid',
  gap: 4,
  textAlign: 'left',
  fontSize: 14,
  fontFamily: 'inherit',
  transition: 'transform 0.12s ease, box-shadow 0.12s ease',
};

const discountBadgeStyle: React.CSSProperties = {
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  padding: '2px 6px',
  borderRadius: 999,
  background: '#e0f2fe',
  color: '#0284c7',
  fontSize: 12,
  fontWeight: 600,
};
