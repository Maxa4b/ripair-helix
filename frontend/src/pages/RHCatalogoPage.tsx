import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import rhcClient from '../api/rhcClient';

type RHCStats = {
  total: number;
  pending: number;
  ok: number;
  errors: number;
};

type RHCItem = {
  id: number;
  name: string;
  slug?: string;
  front_url?: string | null;
  rhc_status: string | null;
  rhc_attempts: number;
  rhc_last_run_at: string | null;
  rhc_generated_title?: string;
  rhc_generated_description?: string;
  rhc_generated_image?: string;
  rhc_last_error?: string | null;
  supplier_reference?: string | null;
  internal_reference?: string | null;
  brand?: { name: string };
  category?: { name: string };
};

type RHCPayload = Record<string, any> | null;

type RHCItemDetails = {
  id: number;
  name: string;
  slug?: string;
  front_url?: string | null;
  rhc_status: string | null;
  rhc_attempts: number;
  rhc_last_run_at: string | null;
  rhc_generated_title?: string;
  rhc_generated_description?: string;
  rhc_generated_image?: string;
  rhc_last_error?: string | null;
  rhc_last_payload?: RHCPayload;
  supplier_reference?: string | null;
  internal_reference?: string | null;
  brand?: string | null;
  category?: string | null;
};

type PagedItems = {
  data: RHCItem[];
  current_page: number;
  last_page: number;
  total: number;
};

type RHCEvent = {
  id: number;
  name: string;
  slug?: string;
  internal_reference?: string | null;
  front_url?: string | null;
  rhc_status: string | null;
  rhc_last_run_at: string | null;
  rhc_last_error?: string | null;
  rhc_attempts: number;
  rhc_generated_title?: string;
  rhc_generated_description?: string;
  rhc_generated_image?: string;
};

function legacyRepairIdFromInternalReference(ref?: string | null): number | null {
  if (!ref) return null;
  if (!ref.startsWith('LEGACY-')) return null;
  const n = Number(ref.slice('LEGACY-'.length));
  return Number.isFinite(n) ? n : null;
}

export default function RHCatalogoPage() {
  const queryClient = useQueryClient();
  const [limit, setLimit] = useState<number>(200);
  const [onlyPending, setOnlyPending] = useState<boolean>(true);
  const [statusFilter, setStatusFilter] = useState<string>('pending');
  const [search, setSearch] = useState<string>('');
  const [page, setPage] = useState<number>(1);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const { data, isLoading, error } = useQuery<RHCStats>({
    queryKey: ['rhcatalogo', 'stats'],
    queryFn: async () => {
      const res = await rhcClient.get('/admin/rhcatalogo/stats');
      return res.data;
    },
    refetchInterval: 15000,
  });

  const itemsQuery = useQuery<PagedItems, Error>({
    queryKey: ['rhcatalogo', 'items', statusFilter, search, page],
    queryFn: async () => {
      const res = await rhcClient.get('/admin/rhcatalogo/items', {
        params: {
          status: statusFilter || undefined,
          q: search || undefined,
          page,
          per_page: 20,
        },
      });
      return res.data;
    },
    refetchInterval: 10000,
  });

  const eventsQuery = useQuery<RHCEvent[]>({
    queryKey: ['rhcatalogo', 'events'],
    queryFn: async () => {
      const res = await rhcClient.get('/admin/rhcatalogo/events');
      return res.data;
    },
    refetchInterval: 8000,
  });

  const detailsQuery = useQuery<RHCItemDetails, Error>({
    queryKey: ['rhcatalogo', 'show', selectedId],
    queryFn: async () => {
      if (!selectedId) throw new Error('missing id');
      const res = await rhcClient.get(`/admin/rhcatalogo/items/${selectedId}`);
      return res.data;
    },
    enabled: !!selectedId,
    refetchInterval: selectedId ? 2000 : false,
  });

  const enqueueMutation = useMutation({
    mutationFn: async () => {
      const res = await rhcClient.post('/admin/rhcatalogo/enqueue', {
        limit,
        only_pending: onlyPending,
      });
      return res.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['rhcatalogo', 'stats'] });
    },
  });

  const progress = useMemo(() => {
    if (!data || data.total === 0) return 0;
    const done = data.ok;
    return Math.min(100, Math.round((done / data.total) * 100));
  }, [data]);

  return (
    <div style={{ display: 'grid', gap: 20 }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div>
          <h1 style={{ margin: 0 }}>RHCATALOGO</h1>
          <p style={{ margin: '4px 0', color: '#64748b' }}>
            Génération automatique des titres/descriptions produits.
          </p>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <label style={{ display: 'flex', flexDirection: 'column', fontSize: 13, color: '#4b5563' }}>
            Limite (lot)
            <input
              type="number"
              value={limit}
              min={1}
              onChange={(e) => setLimit(Number(e.target.value) || 0)}
              style={{
                padding: '8px 10px',
                borderRadius: 8,
                border: '1px solid #e2e8f0',
                width: 120,
              }}
            />
          </label>
          <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#4b5563' }}>
            <input
              type="checkbox"
              checked={onlyPending}
              onChange={(e) => setOnlyPending(e.target.checked)}
            />
            Only pending/erreurs
          </label>
          <button
            type="button"
            onClick={() => enqueueMutation.mutate()}
            disabled={enqueueMutation.isPending}
            style={{
              padding: '10px 14px',
              borderRadius: 8,
              border: 'none',
              background: '#2563eb',
              color: '#fff',
              fontWeight: 600,
              cursor: 'pointer',
            }}
          >
            {enqueueMutation.isPending ? 'Envoi...' : 'Lancer un lot'}
          </button>
        </div>
      </header>

      {isLoading && <p>Chargement des statistiques…</p>}
      {error && <p style={{ color: '#e53e3e' }}>Impossible de charger les stats.</p>}

      {data && (
        <section
          style={{
            background: '#fff',
            borderRadius: 12,
            padding: 18,
            boxShadow: '0 12px 28px rgba(15,23,42,0.06)',
            display: 'grid',
            gap: 12,
          }}
        >
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 12 }}>
            <Metric label="Total" value={data.total} />
            <Metric label="OK" value={data.ok} />
            <Metric label="En attente" value={data.pending} />
            <Metric label="Erreurs" value={data.errors} tone="error" />
          </div>
          <div>
            <p style={{ margin: '6px 0', color: '#4b5563' }}>Avancement global</p>
            <div
              style={{
                height: 12,
                borderRadius: 999,
                background: '#e5e7eb',
                overflow: 'hidden',
              }}
            >
              <div
                style={{
                  width: `${progress}%`,
                  height: '100%',
                  background: '#22c55e',
                  transition: 'width 0.2s ease',
                }}
              />
            </div>
            <small style={{ color: '#64748b' }}>{progress}% traité</small>
          </div>
        </section>
      )}

      <section style={{ display: 'grid', gap: 12 }}>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          <FilterChip active={statusFilter === 'pending'} onClick={() => setStatusFilter('pending')}>
            Pending/Errors
          </FilterChip>
          <FilterChip active={statusFilter === 'ok'} onClick={() => setStatusFilter('ok')}>
            OK
          </FilterChip>
          <FilterChip active={!statusFilter} onClick={() => setStatusFilter('')}>
            Tous
          </FilterChip>
          <input
            type="search"
            placeholder="Rechercher..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            style={{
              padding: '10px 12px',
              borderRadius: 8,
              border: '1px solid #e2e8f0',
              minWidth: 240,
            }}
          />
        </div>

        <div
          style={{
            background: '#fff',
            borderRadius: 12,
            boxShadow: '0 8px 24px rgba(15,23,42,0.06)',
            overflow: 'hidden',
          }}
        >
          <div style={{ padding: 14, borderBottom: '1px solid #e2e8f0' }}>
            <h2 style={{ margin: 0, fontSize: 16 }}>File RHCatalogo</h2>
          </div>
          <div style={{ padding: 14 }}>
            {itemsQuery.isLoading && <p>Chargement…</p>}
            {itemsQuery.error && <p style={{ color: '#e53e3e' }}>Erreur de chargement.</p>}
            {itemsQuery.data && (
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
                <thead>
                  <tr style={{ textAlign: 'left', color: '#64748b' }}>
                    <th style={{ padding: '8px 6px' }}>ID</th>
                    <th style={{ padding: '8px 6px' }}>Titre actuel</th>
                    <th style={{ padding: '8px 6px' }}>Statut</th>
                    <th style={{ padding: '8px 6px' }}>Image</th>
                    <th style={{ padding: '8px 6px' }}>Description</th>
                    <th style={{ padding: '8px 6px' }}>Tentatives</th>
                    <th style={{ padding: '8px 6px' }}>Dernière exécution</th>
                    <th style={{ padding: '8px 6px' }}>Réf</th>
                  </tr>
                </thead>
                <tbody>
            {itemsQuery.data.data.map((item: RHCItem) => (
                    <tr key={item.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                      <td style={{ padding: '8px 6px', color: '#0f172a' }}>{item.id}</td>
                      <td style={{ padding: '8px 6px', color: '#0f172a' }}>
                        {(() => {
                          const repairId = legacyRepairIdFromInternalReference(item.internal_reference);
                          const href = item.front_url ?? (repairId ? `https://boutique.ripair.shop/produits/${repairId}` : null);
                          const label = item.rhc_generated_title || item.name;
                          return href ? (
                            <a
                              href={href}
                              target="_blank"
                              rel="noreferrer"
                              style={{ fontWeight: 600, color: '#0f172a', textDecoration: 'none' }}
                            >
                              {label}
                            </a>
                          ) : (
                            <div style={{ fontWeight: 600 }}>{label}</div>
                          );
                        })()}
                        {item.rhc_last_error && (
                          <div style={{ color: '#e11d48', fontSize: 12 }}>{item.rhc_last_error}</div>
                        )}
                      </td>
                      <td style={{ padding: '8px 6px' }}>
                        <StatusPill status={item.rhc_status} />
                      </td>
                      <td style={{ padding: '8px 6px' }}>
                        {item.rhc_generated_image ? (
                          <img
                            src={item.rhc_generated_image}
                            alt={item.rhc_generated_title || item.name}
                            style={{ width: 64, height: 64, objectFit: 'cover', borderRadius: 8 }}
                          />
                        ) : (
                          <span style={{ color: '#94a3b8', fontSize: 12 }}>—</span>
                        )}
                      </td>
                      <td style={{ padding: '8px 6px', color: '#475569', maxWidth: 320 }}>
                        <div style={{ fontSize: 12, lineHeight: 1.4 }}>
                          {item.rhc_generated_description
                            ? item.rhc_generated_description.slice(0, 160) +
                              (item.rhc_generated_description.length > 160 ? '...' : '')
                            : '-'}
                        </div>
                      </td>
                      <td style={{ padding: '8px 6px' }}>{item.rhc_attempts}</td>
                      <td style={{ padding: '8px 6px', color: '#475569' }}>
                        {item.rhc_last_run_at ? new Date(item.rhc_last_run_at).toLocaleString('fr-FR') : '-'}
                      </td>
                      <td style={{ padding: '8px 6px', color: '#475569' }}>
                        <div>{item.internal_reference || item.supplier_reference || '-'}</div>
                        <button
                          type="button"
                          onClick={() => setSelectedId(item.id)}
                          style={{
                            marginTop: 6,
                            padding: '6px 10px',
                            borderRadius: 8,
                            border: '1px solid #e2e8f0',
                            background: '#fff',
                            cursor: 'pointer',
                            fontSize: 12,
                          }}
                        >
                          Détails LLM
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            {itemsQuery.data && (
              <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 12 }}>
                <button
                  type="button"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1}
                  style={pagerBtnStyle}
                >
                  Précédent
                </button>
                <span style={{ color: '#475569', fontSize: 13 }}>
                  Page {itemsQuery.data.current_page} / {itemsQuery.data.last_page}
                </span>
                <button
                  type="button"
                  onClick={() =>
                    setPage((p) => (itemsQuery.data ? Math.min(itemsQuery.data.last_page, p + 1) : p + 1))
                  }
                  disabled={itemsQuery.data && page >= itemsQuery.data.last_page}
                  style={pagerBtnStyle}
                >
                  Suivant
                </button>
              </div>
            )}
          </div>
        </div>
      </section>

      <section
        style={{
          background: '#fff',
          borderRadius: 12,
          padding: 14,
          boxShadow: '0 8px 24px rgba(15,23,42,0.06)',
        }}
      >
        <div style={{ paddingBottom: 8, borderBottom: '1px solid #e2e8f0' }}>
          <h2 style={{ margin: 0, fontSize: 16 }}>Historique recent</h2>
        </div>
        <div style={{ display: 'grid', gap: 8, paddingTop: 8 }}>
          {eventsQuery.isLoading && <p>Chargement...</p>}
          {eventsQuery.error && <p style={{ color: '#e53e3e' }}>Erreur de chargement.</p>}
          {eventsQuery.data &&
            eventsQuery.data.map((event) => {
              const repairId = legacyRepairIdFromInternalReference(event.internal_reference);
              const href = event.front_url ?? (repairId ? `https://boutique.ripair.shop/produits/${repairId}` : null);
              return (
                <div
                  key={event.id + (event.rhc_last_run_at ?? '')}
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    gap: 12,
                    padding: '10px 12px',
                    border: '1px solid #e2e8f0',
                    borderRadius: 10,
                    background: '#f8fafc',
                  }}
                >
                  {href ? (
                    <a
                      href={href}
                      target="_blank"
                      rel="noreferrer"
                      style={{
                        display: 'flex',
                        gap: 12,
                        alignItems: 'flex-start',
                        textDecoration: 'none',
                        color: 'inherit',
                        flex: 1,
                      }}
                    >
                      {event.rhc_generated_image ? (
                        <img
                          src={event.rhc_generated_image}
                          alt={event.rhc_generated_title || event.name}
                          style={{ width: 60, height: 60, objectFit: 'cover', borderRadius: 8 }}
                        />
                      ) : (
                        <div
                          style={{
                            width: 60,
                            height: 60,
                            borderRadius: 8,
                            background: '#e2e8f0',
                          }}
                        />
                      )}
                      <div>
                        <div style={{ fontWeight: 600, color: '#0f172a' }}>
                          {event.rhc_generated_title || event.name}
                        </div>
                        <div style={{ color: '#475569', fontSize: 12 }}>
                          {event.rhc_last_run_at
                            ? new Date(event.rhc_last_run_at).toLocaleString('fr-FR')
                            : '-'}
                        </div>
                        <div style={{ color: '#475569', fontSize: 12, marginTop: 4 }}>
                          {event.rhc_generated_description
                            ? `${event.rhc_generated_description.slice(0, 160)}${
                                event.rhc_generated_description.length > 160 ? '...' : ''
                              }`
                            : '-'}
                        </div>
                      </div>
                    </a>
                  ) : (
                    <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start', flex: 1 }}>
                    {event.rhc_generated_image ? (
                      <img
                        src={event.rhc_generated_image}
                        alt={event.rhc_generated_title || event.name}
                        style={{ width: 60, height: 60, objectFit: 'cover', borderRadius: 8 }}
                      />
                    ) : (
                      <div
                        style={{
                          width: 60,
                          height: 60,
                          borderRadius: 8,
                          background: '#e2e8f0',
                        }}
                      />
                    )}
                    <div>
                      <div style={{ fontWeight: 600, color: '#0f172a' }}>
                        {event.rhc_generated_title || event.name}
                      </div>
                      <div style={{ color: '#475569', fontSize: 12 }}>
                        {event.rhc_last_run_at
                          ? new Date(event.rhc_last_run_at).toLocaleString('fr-FR')
                          : '-'}
                      </div>
                      <div style={{ color: '#475569', fontSize: 12, marginTop: 4 }}>
                          {event.rhc_generated_description
                            ? `${event.rhc_generated_description.slice(0, 160)}${
                                event.rhc_generated_description.length > 160 ? '...' : ''
                              }`
                            : '-'}
                        </div>
                      </div>
                    </div>
                  )}
                  <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <StatusPill status={event.rhc_status} />
                    <span style={{ fontSize: 12, color: '#475569' }}>Tentatives : {event.rhc_attempts}</span>
                  </div>
                </div>
              );
            })}
        </div>
      </section>

      {enqueueMutation.isError && (
        <p style={{ color: '#e53e3e' }}>Erreur lors de l&apos;envoi du lot.</p>
      )}
      {enqueueMutation.isSuccess && (
        <p style={{ color: '#16a34a' }}>Lot envoyé ({enqueueMutation.data?.enqueued ?? 0} produits).</p>
      )}

      {selectedId && (
        <div
          onClick={() => setSelectedId(null)}
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(15, 23, 42, 0.45)',
            display: 'flex',
            justifyContent: 'center',
            alignItems: 'flex-start',
            padding: 24,
            zIndex: 50,
          }}
        >
          <div
            onClick={(e) => e.stopPropagation()}
            style={{
              width: 'min(1100px, 96vw)',
              background: '#fff',
              borderRadius: 12,
              boxShadow: '0 12px 48px rgba(15,23,42,0.25)',
              overflow: 'hidden',
            }}
          >
            <div
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                padding: 14,
                borderBottom: '1px solid #e2e8f0',
              }}
            >
              <div>
                <h3 style={{ margin: 0, fontSize: 16 }}>Détails RHCatalogo</h3>
                <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 12 }}>Rafraîchissement auto toutes les 2s</p>
              </div>
              <button
                type="button"
                onClick={() => setSelectedId(null)}
                style={{
                  padding: '8px 10px',
                  borderRadius: 8,
                  border: '1px solid #e2e8f0',
                  background: '#fff',
                  cursor: 'pointer',
                }}
              >
                Fermer
              </button>
            </div>

            <div style={{ padding: 14, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              {detailsQuery.isLoading && <p>Chargement...</p>}
              {detailsQuery.error && <p style={{ color: '#e53e3e' }}>Erreur: {detailsQuery.error.message}</p>}
              {detailsQuery.data && (
                <>
                  <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 12 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12 }}>
                      <div>
                        <div style={{ fontWeight: 700, color: '#0f172a' }}>
                          {detailsQuery.data.rhc_generated_title || detailsQuery.data.name}
                        </div>
                        <div style={{ color: '#64748b', fontSize: 12, marginTop: 4 }}>
                          ID {detailsQuery.data.id} • {detailsQuery.data.supplier_reference || '-'} •{' '}
                          {detailsQuery.data.internal_reference || '-'}
                        </div>
                      </div>
                      <StatusPill status={detailsQuery.data.rhc_status} />
                    </div>

                    {detailsQuery.data.front_url && (
                      <a
                        href={detailsQuery.data.front_url}
                        target="_blank"
                        rel="noreferrer"
                        style={{
                          display: 'inline-block',
                          marginTop: 10,
                          color: '#2563eb',
                          textDecoration: 'none',
                          fontWeight: 600,
                        }}
                      >
                        Ouvrir sur le site
                      </a>
                    )}

                    {detailsQuery.data.rhc_last_error && (
                      <div style={{ marginTop: 10, color: '#e11d48', fontSize: 12 }}>
                        {detailsQuery.data.rhc_last_error}
                      </div>
                    )}
                  </div>

                  <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 12 }}>
                    <div style={{ fontWeight: 700, marginBottom: 6 }}>Payload (scrape + LLM)</div>
                    <pre
                      style={{
                        margin: 0,
                        fontSize: 12,
                        background: '#0b1220',
                        color: '#e2e8f0',
                        padding: 12,
                        borderRadius: 10,
                        overflow: 'auto',
                        maxHeight: 520,
                      }}
                    >
                      {JSON.stringify(detailsQuery.data.rhc_last_payload ?? null, null, 2)}
                    </pre>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      )}

    </div>
  );
}

function StatusPill({ status }: { status: string | null }) {
  const label = status || 'pending';
  const map: Record<string, { bg: string; text: string }> = {
    ok: { bg: '#dcfce7', text: '#15803d' },
    error: { bg: '#fee2e2', text: '#b91c1c' },
    processing: { bg: '#e0f2fe', text: '#0369a1' },
    pending: { bg: '#fef9c3', text: '#854d0e' },
  };
  const colors = map[label] ?? map['pending'];
  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        padding: '4px 10px',
        borderRadius: 999,
        background: colors.bg,
        color: colors.text,
        fontSize: 12,
        fontWeight: 600,
      }}
    >
      {label}
    </span>
  );
}

function FilterChip({
  active,
  children,
  onClick,
}: {
  active: boolean;
  children: React.ReactNode;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      style={{
        border: active ? '1px solid #2563eb' : '1px solid #e2e8f0',
        color: active ? '#2563eb' : '#475569',
        background: active ? '#eff6ff' : '#fff',
        padding: '8px 12px',
        borderRadius: 999,
        cursor: 'pointer',
        fontWeight: 600,
      }}
    >
      {children}
    </button>
  );
}

const pagerBtnStyle: React.CSSProperties = {
  padding: '8px 12px',
  borderRadius: 8,
  border: '1px solid #e2e8f0',
  background: '#fff',
  cursor: 'pointer',
};

function Metric({ label, value, tone = 'default' }: { label: string; value: number; tone?: 'default' | 'error' }) {
  const toneColor = tone === 'error' ? '#e53e3e' : '#111827';
  return (
    <div
      style={{
        background: '#f8fafc',
        borderRadius: 10,
        padding: '12px 14px',
        border: '1px solid #e2e8f0',
      }}
    >
      <p style={{ margin: 0, color: '#64748b' }}>{label}</p>
      <strong style={{ fontSize: 22, color: toneColor }}>{value}</strong>
    </div>
  );
}
