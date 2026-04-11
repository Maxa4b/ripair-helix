import type { ProspectingCompany, ProspectingStatus } from '../../hooks/useProspecting';
import ProspectingStatusButtons from './ProspectingStatusButtons';
import { statusLabel } from './prospectingStatusMeta';
import { copyText } from './prospectingUtils';

type Props = {
  company: ProspectingCompany;
  isSaving?: boolean;
  onClose: () => void;
  onOpenDetails: () => void;
  onChangeStatus: (status: ProspectingStatus) => void;
  onDisable: () => void;
};

export default function ProspectingQuickCard({
  company,
  isSaving,
  onClose,
  onOpenDetails,
  onChangeStatus,
  onDisable,
}: Props) {
  const handleCopy = async (value?: string | null, label?: string) => {
    const copied = await copyText(value);
    if (!copied) {
      window.alert(`Copie impossible pour ${label ?? 'la valeur demandee'}.`);
    }
  };

  return (
    <div className="prospecting-quick-card">
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12 }}>
        <div>
          <p style={{ margin: 0, color: '#64748b', fontSize: 12, fontWeight: 800 }}>{statusLabel(company.contact_status)}</p>
          <h3 style={{ margin: '4px 0 0', fontSize: '1.1rem' }}>{company.name}</h3>
          <p style={{ margin: '6px 0 0', color: '#64748b' }}>
            {[company.city, company.postal_code, company.segment].filter(Boolean).join(' · ') || 'Entreprise'}
          </p>
        </div>
        <button type="button" onClick={onClose} className="prospecting-button prospecting-button--ghost" style={{ padding: '10px 12px' }}>
          Fermer
        </button>
      </div>

      <div style={{ display: 'grid', gap: 10, marginTop: 14 }}>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button type="button" className="prospecting-button prospecting-button--ghost" onClick={() => void handleCopy(company.email, 'email')}>
            Copier email
          </button>
          <button type="button" className="prospecting-button prospecting-button--ghost" onClick={() => void handleCopy(company.phone, 'telephone')}>
            Copier telephone
          </button>
          <button type="button" className="prospecting-button prospecting-button--primary" onClick={onOpenDetails}>
            Voir fiche
          </button>
          {!company.email && !company.phone ? (
            <button type="button" className="prospecting-button prospecting-button--danger" onClick={onDisable} disabled={isSaving}>
              Desactiver le point
            </button>
          ) : null}
        </div>

        <div style={{ color: '#475569', fontSize: 14 }}>
          <div>{company.email ?? 'Email absent'}</div>
          <div>{company.phone ?? 'Telephone absent'}</div>
        </div>

        <ProspectingStatusButtons status={company.contact_status} disabled={isSaving} onChange={onChangeStatus} />
      </div>
    </div>
  );
}
