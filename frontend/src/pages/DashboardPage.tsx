import { useQuery } from '@tanstack/react-query';
import apiClient from '../api/client';

type DashboardSummary = {
  next_appointments: Array<{
    id: number;
    service_label: string;
    start_datetime: string;
    status: string;
    customer?: {
      name?: string;
    };
  }>;
  counts: Record<string, number>;
};

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('fr-FR', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    hour: '2-digit',
    minute: '2-digit',
  });

export default function DashboardPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['dashboard', 'summary'],
    queryFn: async (): Promise<DashboardSummary> => {
      const response = await apiClient.get('/dashboard/summary');
      return response.data;
    },
  });

  if (isLoading) {
    return <p>Chargement du tableau de bord…</p>;
  }

  if (error) {
    return (
      <p style={{ color: '#e53e3e' }}>
        Impossible de récupérer le tableau de bord. Vérifie la connexion à l’API.
      </p>
    );
  }

  return (
    <div style={{ display: 'grid', gap: 24 }}>
      <section style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16 }}>
        {Object.entries(data?.counts ?? {}).map(([label, value]) => (
          <div
            key={label}
            style={{
              background: '#fff',
              borderRadius: 14,
              padding: '18px 20px',
              boxShadow: '0 12px 32px rgba(15,23,42,0.06)',
            }}
          >
            <p style={{ margin: 0, color: '#64748b', textTransform: 'capitalize' }}>
              {label.replace('_', ' ')}
            </p>
            <strong style={{ fontSize: 26 }}>{value}</strong>
          </div>
        ))}
      </section>

      <section style={{ background: '#fff', borderRadius: 14, boxShadow: '0 12px 32px rgba(15,23,42,0.06)' }}>
        <header style={{ padding: '18px 20px', borderBottom: '1px solid #e2e8f0' }}>
          <h2 style={{ margin: 0, fontSize: 18 }}>Prochains rendez-vous</h2>
        </header>
        <div style={{ padding: 20, display: 'grid', gap: 12 }}>
          {(data?.next_appointments ?? []).length === 0 && <p>Aucun rendez-vous à venir.</p>}
          {(data?.next_appointments ?? []).map((appointment) => (
            <article
              key={appointment.id}
              style={{
                border: '1px solid #e2e8f0',
                borderRadius: 10,
                padding: '12px 16px',
                background: '#f8fafc',
              }}
            >
              <h3 style={{ margin: 0, fontSize: 16 }}>{appointment.service_label}</h3>
              <p style={{ margin: '6px 0 0', color: '#64748b' }}>{formatDate(appointment.start_datetime)}</p>
              <p style={{ margin: '4px 0 0', color: '#1a202c' }}>
                Client : {appointment.customer?.name ?? '—'}
              </p>
              <span
                style={{
                  marginTop: 8,
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: 8,
                  fontSize: 12,
                  padding: '4px 10px',
                  borderRadius: 999,
                  background: '#edf2ff',
                  color: '#3b5bdb',
                  textTransform: 'uppercase',
                }}
              >
                {appointment.status}
              </span>
            </article>
          ))}
        </div>
      </section>
    </div>
  );
}
