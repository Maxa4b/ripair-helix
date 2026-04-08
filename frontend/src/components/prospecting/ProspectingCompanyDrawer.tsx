import { useEffect, useState } from 'react';
import type { ProspectingCompany, ProspectingStatus } from '../../hooks/useProspecting';
import ProspectingStatusButtons from './ProspectingStatusButtons';
import { copyText, formatDateTime } from './prospectingUtils';

type Props = {
  company: ProspectingCompany | null | undefined;
  isLoading?: boolean;
  isSaving?: boolean;
  onClose: () => void;
  onChangeStatus: (status: ProspectingStatus) => void;
  onSave: (payload: { notes: string | null; contact_owner: string | null }) => void;
};

export default function ProspectingCompanyDrawer({
  company,
  isLoading,
  isSaving,
  onClose,
  onChangeStatus,
  onSave,
}: Props) {
  const [owner, setOwner] = useState('');
  const [notes, setNotes] = useState('');

  useEffect(() => {
    setOwner(company?.contact_owner ?? '');
    setNotes(company?.notes ?? '');
  }, [company?.contact_owner, company?.notes, company?.id]);

  if (!company && !isLoading) {
    return null;
  }

  const handleCopy = async (value?: string | null, label?: string) => {
    const copied = await copyText(value);
    if (!copied) {
      window.alert(`Copie impossible pour ${label ?? 'la valeur demandee'}.`);
    }
  };

  return (
    <aside className="prospecting-drawer">
      <div className="prospecting-drawer__header" style={{ borderBottom: '1px solid rgba(148,163,184,0.18)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 14 }}>
          <div>
            <h2 style={{ margin: 0 }}>{company?.name ?? 'Chargement...'}</h2>
            <p style={{ margin: '8px 0 0', color: '#64748b' }}>
              {[company?.city, company?.postal_code, company?.segment].filter(Boolean).join(' · ') || 'Entreprise'}
            </p>
          </div>
          <button type="button" onClick={onClose} className="prospecting-button prospecting-button--ghost">
            Fermer
          </button>
        </div>
      </div>

      <div className="prospecting-drawer__body">
        {isLoading || !company ? (
          <p style={{ margin: 0, color: '#64748b' }}>Chargement de la fiche entreprise...</p>
        ) : (
          <>
            <section style={{ display: 'grid', gap: 12 }}>
              <ProspectingStatusButtons status={company.contact_status} disabled={isSaving} onChange={onChangeStatus} />
              <div className="prospecting-actions">
                <button type="button" className="prospecting-button prospecting-button--ghost" onClick={() => void handleCopy(company.email, 'email')}>
                  Copier email
                </button>
                <button type="button" className="prospecting-button prospecting-button--ghost" onClick={() => void handleCopy(company.phone, 'telephone')}>
                  Copier telephone
                </button>
              </div>
            </section>

            <section style={{ display: 'grid', gap: 12 }}>
              <div className="prospecting-field">
                Contact owner
                <input value={owner} onChange={(event) => setOwner(event.target.value)} placeholder="Ex: Alice / SDR A" />
              </div>
              <div className="prospecting-field">
                Notes
                <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={5} placeholder="Etat du contact, blocages, prochaines actions..." />
              </div>
              <button
                type="button"
                className="prospecting-button prospecting-button--primary"
                onClick={() => onSave({ notes: notes.trim() || null, contact_owner: owner.trim() || null })}
                disabled={isSaving}
              >
                {isSaving ? 'Enregistrement...' : 'Enregistrer'}
              </button>
            </section>

            <section style={{ display: 'grid', gap: 8 }}>
              <h3 style={{ margin: 0 }}>Coordonnees</h3>
              <div style={{ display: 'grid', gap: 6, color: '#475569' }}>
                <div>Email: {company.email ?? 'Absent'}</div>
                <div>Telephone: {company.phone ?? 'Absent'}</div>
                <div>Site: {company.website ?? 'Absent'}</div>
                <div>Adresse: {[company.address, company.city, company.postal_code, company.country].filter(Boolean).join(', ') || 'Absente'}</div>
                <div>SIREN: {company.siren ?? '-'}</div>
                <div>SIRET: {company.siret ?? '-'}</div>
                <div>Score: {company.relevance_score}</div>
                <div>Maj: {formatDateTime(company.updated_at)}</div>
              </div>
            </section>

            <section style={{ display: 'grid', gap: 10 }}>
              <h3 style={{ margin: 0 }}>Historique</h3>
              <div className="prospecting-history">
                {(company.history ?? []).length === 0 ? (
                  <div className="prospecting-history-item">Aucune modification historisee.</div>
                ) : (
                  company.history?.map((entry) => (
                    <article key={entry.id} className="prospecting-history-item">
                      <strong>{entry.changed_by_name ?? 'Systeme'}</strong>
                      <div style={{ marginTop: 6, color: '#475569' }}>
                        {entry.previous_status ?? '-'} → {entry.new_status ?? '-'}
                      </div>
                      <div style={{ marginTop: 4, color: '#64748b', fontSize: 13 }}>{entry.change_note ?? entry.source}</div>
                      <div style={{ marginTop: 6, color: '#94a3b8', fontSize: 12 }}>{formatDateTime(entry.changed_at)}</div>
                    </article>
                  ))
                )}
              </div>
            </section>
          </>
        )}
      </div>
    </aside>
  );
}
