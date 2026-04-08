import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import ProspectingCompanyDrawer from '../components/prospecting/ProspectingCompanyDrawer';
import ProspectingMap from '../components/prospecting/ProspectingMap';
import ProspectingQuickCard from '../components/prospecting/ProspectingQuickCard';
import { statusLabel } from '../components/prospecting/prospectingStatusMeta';
import { formatDateTime } from '../components/prospecting/prospectingUtils';
import {
  useProspectingCompanies,
  useProspectingCompany,
  useProspectingStats,
  useRunProspectingExcelSync,
  useUpdateProspectingCompany,
  useUpdateProspectingCompanyStatus,
  type ProspectingCompany,
  type ProspectingStatus,
} from '../hooks/useProspecting';
import '../styles/prospecting.css';

const PAGE_SIZE = 12;

export default function ProspectingPage() {
  const [status, setStatus] = useState('all');
  const [segment, setSegment] = useState('all');
  const [search, setSearch] = useState('');
  const [zone, setZone] = useState('');
  const [missingContact, setMissingContact] = useState(false);
  const [bounds, setBounds] = useState<string | null>(null);
  const [selectedCompanyId, setSelectedCompanyId] = useState<number | null>(null);
  const [drawerCompanyId, setDrawerCompanyId] = useState<number | null>(null);
  const [listPage, setListPage] = useState(1);

  const deferredSearch = useDeferredValue(search.trim());
  const deferredZone = useDeferredValue(zone.trim());

  const filters = useMemo(
    () => ({
      status,
      segment,
      q: deferredSearch || undefined,
      zone: deferredZone || undefined,
      missing_contact: missingContact,
    }),
    [deferredSearch, deferredZone, missingContact, segment, status],
  );

  const companiesQuery = useProspectingCompanies({
    ...filters,
    bounds,
    only_geocoded: true,
    limit: 2000,
  });
  const statsQuery = useProspectingStats(filters);
  const statusMutation = useUpdateProspectingCompanyStatus();
  const updateMutation = useUpdateProspectingCompany();
  const syncMutation = useRunProspectingExcelSync();
  const detailQuery = useProspectingCompany(drawerCompanyId);

  const companies = useMemo(() => companiesQuery.data?.data ?? [], [companiesQuery.data]);
  const stats = statsQuery.data;

  const selectedCompany = useMemo(
    () => companies.find((company) => company.id === selectedCompanyId) ?? null,
    [companies, selectedCompanyId],
  );

  const drawerCompany = detailQuery.data ?? (drawerCompanyId === selectedCompany?.id ? selectedCompany : null);

  const segmentOptions = useMemo(() => {
    const values = new Set<string>();
    companies.forEach((company) => {
      if (company.segment) {
        values.add(company.segment);
      }
    });

    return Array.from(values).sort((left, right) => left.localeCompare(right, 'fr'));
  }, [companies]);

  useEffect(() => {
    setListPage(1);
  }, [bounds, deferredSearch, deferredZone, missingContact, segment, status]);

  const totalPages = Math.max(1, Math.ceil(companies.length / PAGE_SIZE));
  const pagedCompanies = useMemo(
    () => companies.slice((listPage - 1) * PAGE_SIZE, listPage * PAGE_SIZE),
    [companies, listPage],
  );

  const handleSelectCompany = (company: ProspectingCompany | null) => {
    setSelectedCompanyId(company?.id ?? null);
  };

  const openDrawer = (companyId: number) => {
    setSelectedCompanyId(companyId);
    setDrawerCompanyId(companyId);
  };

  const handleStatusChange = async (statusValue: ProspectingStatus) => {
    const target = drawerCompany ?? selectedCompany;
    if (!target) {
      return;
    }

    try {
      await statusMutation.mutateAsync({
        id: target.id,
        contact_status: statusValue,
        version: target.version,
        contact_owner: target.contact_owner,
        notes: target.notes,
      });
    } catch (error) {
      console.error(error);
      window.alert('Mise a jour du statut impossible.');
    }
  };

  const handleSaveDetails = async (payload: { notes: string | null; contact_owner: string | null }) => {
    const target = drawerCompany;
    if (!target) {
      return;
    }

    try {
      await updateMutation.mutateAsync({
        id: target.id,
        payload: {
          notes: payload.notes,
          contact_owner: payload.contact_owner,
          version: target.version,
        },
      });
    } catch (error) {
      console.error(error);
      window.alert('Enregistrement impossible. Verifiez la version ou rechargez la fiche.');
    }
  };

  return (
    <div className="prospecting-page">
      <section className="prospecting-hero">
        <div>
          <h1 className="prospecting-hero__title">Prospection cartographique</h1>
          <p className="prospecting-hero__subtitle">
            Vue Google Maps haute densite pour piloter la prospection, suivre les statuts de contact et resynchroniser un miroir Excel sans jamais sortir la base Helix du role de source de verite.
          </p>
        </div>
        <div className="prospecting-actions">
          <button
            type="button"
            className="prospecting-button prospecting-button--primary"
            onClick={() => syncMutation.mutate('resync')}
            disabled={syncMutation.isPending}
          >
            {syncMutation.isPending ? 'Resync en cours...' : 'Resync Excel'}
          </button>
          <button
            type="button"
            className="prospecting-button prospecting-button--ghost"
            onClick={() => syncMutation.mutate('export')}
            disabled={syncMutation.isPending}
          >
            Exporter Excel
          </button>
        </div>
      </section>

      <div className="prospecting-layout">
        <aside className="prospecting-panel">
          <div style={{ display: 'grid', gap: 6 }}>
            <h2 className="prospecting-panel__title">Filtres et progression</h2>
            <p style={{ margin: 0, color: '#64748b' }}>
              {stats
                ? `${stats.total} entreprises qualifiees, ${stats.coverage_rate}% de couverture.`
                : 'Chargement des statistiques...'}
            </p>
          </div>

          <div className="prospecting-stats">
            <StatCard label="Total" value={stats?.total ?? 0} />
            <StatCard label="Non contactes" value={stats?.non_contacte ?? 0} />
            <StatCard label="En cours" value={stats?.en_cours_de_contact ?? 0} />
            <StatCard label="Contactes" value={stats?.contacte ?? 0} />
          </div>

          <div className="prospecting-legend">
            <LegendChip label="Non contacte" colorClass="prospecting-chip__dot--red" />
            <LegendChip label="En cours" colorClass="prospecting-chip__dot--blue" />
            <LegendChip label="Contacte" colorClass="prospecting-chip__dot--green" />
          </div>

          <label className="prospecting-field">
            Recherche texte
            <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nom, SIREN, email, telephone..." />
          </label>

          <label className="prospecting-field">
            Zone geographique
            <input value={zone} onChange={(event) => setZone(event.target.value)} placeholder="Ville, CP, pays, adresse..." />
          </label>

          <label className="prospecting-field">
            Statut
            <select value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="all">Tous</option>
              <option value="non_contacte">Non contactes</option>
              <option value="en_cours_de_contact">En cours</option>
              <option value="contacte">Contactes</option>
            </select>
          </label>

          <label className="prospecting-field">
            Segment
            <select value={segment} onChange={(event) => setSegment(event.target.value)}>
              <option value="all">Tous</option>
              {segmentOptions.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </label>

          <label className="prospecting-toggle">
            <input type="checkbox" checked={missingContact} onChange={(event) => setMissingContact(event.target.checked)} />
            Montrer les entreprises sans email ou telephone
          </label>

          <section style={{ display: 'grid', gap: 10 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <h3 style={{ margin: 0 }}>Entreprises visibles</h3>
              <span style={{ color: '#64748b', fontSize: 13 }}>{companiesQuery.data?.meta.returned ?? 0} chargees</span>
            </div>

            {companiesQuery.isLoading ? (
              <p style={{ margin: 0, color: '#64748b' }}>Chargement du viewport...</p>
            ) : companiesQuery.isError ? (
              <p style={{ margin: 0, color: '#b91c1c' }}>Impossible de charger les entreprises.</p>
            ) : companies.length === 0 ? (
              <p style={{ margin: 0, color: '#64748b' }}>Aucune entreprise dans ce filtre.</p>
            ) : (
              <>
                <div className="prospecting-list">
                  {pagedCompanies.map((company) => (
                    <button
                      key={company.id}
                      type="button"
                      className={`prospecting-list-item${selectedCompanyId === company.id ? ' prospecting-list-item--active' : ''}`}
                      onClick={() => openDrawer(company.id)}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, textAlign: 'left' }}>
                        <strong>{company.name}</strong>
                        <span style={{ color: '#64748b', fontSize: 12 }}>{statusLabel(company.contact_status)}</span>
                      </div>
                      <div style={{ textAlign: 'left', color: '#64748b', fontSize: 14 }}>
                        {[company.city, company.segment].filter(Boolean).join(' · ') || 'Sans segmentation'}
                      </div>
                      <div style={{ textAlign: 'left', color: '#94a3b8', fontSize: 12 }}>
                        Derniere action: {formatDateTime(company.last_contact_at)}
                      </div>
                    </button>
                  ))}
                </div>

                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10 }}>
                  <button
                    type="button"
                    className="prospecting-button prospecting-button--ghost"
                    onClick={() => setListPage((current) => Math.max(1, current - 1))}
                    disabled={listPage <= 1}
                  >
                    Page precedente
                  </button>
                  <span style={{ alignSelf: 'center', color: '#64748b' }}>
                    {listPage} / {totalPages}
                  </span>
                  <button
                    type="button"
                    className="prospecting-button prospecting-button--ghost"
                    onClick={() => setListPage((current) => Math.min(totalPages, current + 1))}
                    disabled={listPage >= totalPages}
                  >
                    Page suivante
                  </button>
                </div>
              </>
            )}
          </section>
        </aside>

        <section>
          <ProspectingMap companies={companies} selectedCompanyId={selectedCompanyId} onSelectCompany={handleSelectCompany} onBoundsChange={setBounds}>
            {selectedCompany ? (
              <ProspectingQuickCard
                company={selectedCompany}
                isSaving={statusMutation.isPending}
                onClose={() => setSelectedCompanyId(null)}
                onOpenDetails={() => openDrawer(selectedCompany.id)}
                onChangeStatus={handleStatusChange}
              />
            ) : null}
          </ProspectingMap>
        </section>
      </div>

      <ProspectingCompanyDrawer
        company={drawerCompany}
        isLoading={detailQuery.isLoading}
        isSaving={statusMutation.isPending || updateMutation.isPending}
        onClose={() => setDrawerCompanyId(null)}
        onChangeStatus={handleStatusChange}
        onSave={handleSaveDetails}
      />
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: number | string }) {
  return (
    <article className="prospecting-stat">
      <p className="prospecting-stat__label">{label}</p>
      <p className="prospecting-stat__value">{value}</p>
    </article>
  );
}

function LegendChip({ label, colorClass }: { label: string; colorClass: string }) {
  return (
    <span className="prospecting-chip">
      <span className={`prospecting-chip__dot ${colorClass}`} />
      {label}
    </span>
  );
}
