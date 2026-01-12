import { useMemo, useState } from 'react';
import { useAuth } from '../hooks/useAuth';
import { useModerateReview, useReviews, type CustomerReview } from '../hooks/useReviews';

type FilterState = 'pending' | 'approved' | 'rejected' | 'all';

function formatDate(iso?: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function stars(rating: number): string {
  const safe = Math.max(1, Math.min(5, rating || 0));
  return '★'.repeat(safe);
}

function getDisplayName(review: CustomerReview): string {
  if (!review.show_name) return 'Client (anonyme)';
  const composed = `${review.first_name ?? ''} ${review.last_name ?? ''}`.trim();
  return composed || 'Client';
}

export default function ReviewsPage() {
  const { user } = useAuth();
  const canModerate = user?.role === 'owner' || user?.role === 'manager';

  const [filter, setFilter] = useState<FilterState>('pending');
  const [search, setSearch] = useState('');
  const [feedback, setFeedback] = useState('');
  const [notesDraft, setNotesDraft] = useState<Record<number, string>>({});

  const filters = useMemo(
    () => ({
      status: filter,
      search: search.trim() || undefined,
      limit: 200,
    }),
    [filter, search],
  );

  const reviewsQuery = useReviews(canModerate ? filters : {});
  const reviews = useMemo(() => reviewsQuery.data ?? [], [reviewsQuery.data]);
  const moderateMutation = useModerateReview();

  const showFeedback = (message: string) => setFeedback(message);

  const handleModerate = async (review: CustomerReview, status: 'approved' | 'rejected') => {
    if (!canModerate) return;
    if (
      !window.confirm(
        status === 'approved'
          ? `Valider l'avis de ${getDisplayName(review)} ?`
          : `Refuser l'avis de ${getDisplayName(review)} ?`,
      )
    ) {
      return;
    }

    try {
      await moderateMutation.mutateAsync({
        id: review.id,
        status,
        admin_note: notesDraft[review.id] ? notesDraft[review.id] : null,
      });
      showFeedback(status === 'approved' ? 'Avis validé.' : 'Avis refusé.');
      setNotesDraft((prev) => {
        const next = { ...prev };
        delete next[review.id];
        return next;
      });
    } catch (error) {
      console.error(error);
      window.alert("Impossible de modérer l'avis (API).");
    }
  };

  if (!canModerate) {
    return (
      <div style={pageStyle}>
        <header style={headerStyle}>
          <h1 style={{ margin: 0 }}>Avis</h1>
          <p style={{ margin: 0, color: '#475569' }}>Accès réservé aux comptes owner/manager.</p>
        </header>
      </div>
    );
  }

  return (
    <div style={pageStyle}>
      <header style={headerStyle}>
        <div>
          <h1 style={{ margin: 0 }}>Avis</h1>
          <p style={{ margin: '6px 0 0', color: '#475569' }}>
            Valide ou refuse les avis avant publication sur le site.
          </p>
        </div>
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Rechercher (commentaire, nom)…"
          style={searchStyle}
        />
      </header>

      {feedback && (
        <div style={feedbackStyle}>
          <span>{feedback}</span>
          <button type="button" onClick={() => setFeedback('')} style={feedbackButton}>
            OK
          </button>
        </div>
      )}

      <section style={sectionStyle}>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <FilterChip active={filter === 'pending'} onClick={() => setFilter('pending')}>
            En attente
          </FilterChip>
          <FilterChip active={filter === 'approved'} onClick={() => setFilter('approved')}>
            Validés
          </FilterChip>
          <FilterChip active={filter === 'rejected'} onClick={() => setFilter('rejected')}>
            Refusés
          </FilterChip>
          <FilterChip active={filter === 'all'} onClick={() => setFilter('all')}>
            Tous
          </FilterChip>
        </div>

        {reviewsQuery.isLoading ? (
          <p style={{ margin: 0, color: '#475569' }}>Chargement…</p>
        ) : reviewsQuery.isError ? (
          <p style={{ margin: 0, color: '#b91c1c' }}>Impossible de charger les avis.</p>
        ) : reviews.length === 0 ? (
          <p style={{ margin: 0, color: '#475569' }}>Aucun avis dans cette catégorie.</p>
        ) : (
          <div style={cardGrid}>
            {reviews.map((review) => {
              const isPending = review.status === 'pending';
              return (
                <article key={review.id} style={cardStyle}>
                  <header style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
                    <div style={{ display: 'grid', gap: 6 }}>
                      <div style={{ fontWeight: 800, color: '#0f172a' }}>
                        {stars(review.rating)}{' '}
                        <span style={{ color: '#64748b', fontWeight: 600 }}>({review.rating}/5)</span>
                      </div>
                      <div style={{ color: '#475569', fontSize: 13 }}>
                        {getDisplayName(review)} · {formatDate(review.created_at)}
                      </div>
                      {review.source_page && (
                        <div style={{ color: '#94a3b8', fontSize: 12 }}>Source : {review.source_page}</div>
                      )}
                    </div>
                    <span
                      style={{
                        padding: '4px 10px',
                        borderRadius: 999,
                        fontSize: 12,
                        fontWeight: 800,
                        background:
                          review.status === 'approved'
                            ? '#dcfce7'
                            : review.status === 'rejected'
                              ? '#fee2e2'
                              : '#fff7ed',
                        color:
                          review.status === 'approved'
                            ? '#166534'
                            : review.status === 'rejected'
                              ? '#b91c1c'
                              : '#9a3412',
                        border: '1px solid rgba(15,23,42,0.08)',
                      }}
                    >
                      {review.status === 'approved' ? 'Validé' : review.status === 'rejected' ? 'Refusé' : 'En attente'}
                    </span>
                  </header>

                  <p style={{ margin: 0, color: '#0f172a', lineHeight: 1.6, whiteSpace: 'pre-wrap' }}>
                    {review.comment}
                  </p>

                  <footer style={cardFooterStyle}>
                    <label style={{ ...labelStyle, flex: 1, minWidth: 220 }}>
                      Note admin (optionnel)
                      <input
                        value={notesDraft[review.id] ?? review.admin_note ?? ''}
                        onChange={(e) =>
                          setNotesDraft((prev) => ({
                            ...prev,
                            [review.id]: e.target.value,
                          }))
                        }
                        placeholder="Ex: spam / hors sujet / OK"
                        style={inputStyle}
                        disabled={!isPending || moderateMutation.isPending}
                      />
                    </label>

                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                      <button
                        type="button"
                        onClick={() => handleModerate(review, 'approved')}
                        style={{
                          ...actionButton,
                          background: isPending ? '#dcfce7' : '#e2e8f0',
                          color: isPending ? '#166534' : '#475569',
                          cursor: isPending ? 'pointer' : 'not-allowed',
                        }}
                        disabled={!isPending || moderateMutation.isPending}
                      >
                        ✓ Valider
                      </button>
                      <button
                        type="button"
                        onClick={() => handleModerate(review, 'rejected')}
                        style={{
                          ...actionButton,
                          background: isPending ? '#fee2e2' : '#e2e8f0',
                          color: isPending ? '#b91c1c' : '#475569',
                          cursor: isPending ? 'pointer' : 'not-allowed',
                        }}
                        disabled={!isPending || moderateMutation.isPending}
                      >
                        ✕ Refuser
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
        padding: '6px 12px',
        borderRadius: 999,
        border: 'none',
        cursor: 'pointer',
        background: active ? '#c7d2fe' : '#e2e8f0',
        color: active ? '#1e3a8a' : '#334155',
        fontSize: 13,
        fontWeight: 700,
      }}
    >
      {children}
    </button>
  );
}

const pageStyle: React.CSSProperties = {
  display: 'grid',
  gap: 16,
};

const headerStyle: React.CSSProperties = {
  display: 'flex',
  justifyContent: 'space-between',
  gap: 16,
  flexWrap: 'wrap',
  alignItems: 'flex-start',
};

const searchStyle: React.CSSProperties = {
  borderRadius: 10,
  border: '1px solid #cbd5f5',
  padding: '10px 12px',
  fontSize: 14,
  background: '#fff',
  minWidth: 260,
};

const sectionStyle: React.CSSProperties = {
  background: '#fff',
  borderRadius: 12,
  padding: 20,
  boxShadow: '0 12px 32px rgba(15,23,42,0.07)',
  display: 'grid',
  gap: 16,
};

const cardGrid: React.CSSProperties = {
  display: 'grid',
  gap: 16,
  gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))',
};

const cardStyle: React.CSSProperties = {
  display: 'grid',
  gap: 14,
  padding: 18,
  borderRadius: 14,
  border: '1px solid #e2e8f0',
  background: '#fff',
  boxShadow: '0 10px 24px rgba(15,23,42,0.08)',
};

const cardFooterStyle: React.CSSProperties = {
  display: 'flex',
  justifyContent: 'space-between',
  alignItems: 'flex-end',
  gap: 12,
  flexWrap: 'wrap',
};

const labelStyle: React.CSSProperties = {
  display: 'grid',
  gap: 6,
  fontSize: 13,
  color: '#475569',
  fontWeight: 700,
};

const inputStyle: React.CSSProperties = {
  borderRadius: 10,
  border: '1px solid #cbd5f5',
  padding: '10px 12px',
  fontSize: 14,
  background: '#fff',
};

const actionButton: React.CSSProperties = {
  border: 'none',
  borderRadius: 10,
  padding: '10px 14px',
  fontWeight: 900,
};

const feedbackStyle: React.CSSProperties = {
  padding: '10px 14px',
  borderRadius: 10,
  background: '#ecfdf5',
  border: '1px solid #bbf7d0',
  color: '#047857',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'space-between',
  gap: 12,
};

const feedbackButton: React.CSSProperties = {
  border: 'none',
  borderRadius: 8,
  padding: '8px 12px',
  background: '#047857',
  color: '#fff',
  cursor: 'pointer',
  fontWeight: 700,
};

