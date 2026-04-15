import type { EnrichmentRemoteEntry, EnrichmentRemoteListing } from '../../hooks/useProspectingEnrichment';

type ProspectingFileBrowserDialogProps = {
  open: boolean;
  title: string;
  description: string;
  listing: EnrichmentRemoteListing | undefined;
  isLoading: boolean;
  errorMessage: string | null;
  selectingPath: string | null;
  onClose: () => void;
  onNavigate: (path: string) => void;
  onSelectFile: (entry: EnrichmentRemoteEntry) => void;
};

function formatBytes(value: number) {
  if (value < 1024) return `${value} o`;
  if (value < 1024 ** 2) return `${(value / 1024).toFixed(1)} Ko`;
  if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(1)} Mo`;
  return `${(value / 1024 ** 3).toFixed(2)} Go`;
}

function formatModifiedAt(value: string) {
  return new Date(value).toLocaleString('fr-FR');
}

export default function ProspectingFileBrowserDialog({
  open,
  title,
  description,
  listing,
  isLoading,
  errorMessage,
  selectingPath,
  onClose,
  onNavigate,
  onSelectFile,
}: ProspectingFileBrowserDialogProps) {
  if (!open) {
    return null;
  }

  return (
    <div className="prospecting-modal-backdrop" role="presentation" onClick={onClose}>
      <section
        className="prospecting-modal"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onClick={(event) => event.stopPropagation()}
      >
        <header className="prospecting-modal__header">
          <div style={{ display: 'grid', gap: 6 }}>
            <h2 style={{ margin: 0 }}>{title}</h2>
            <p style={{ margin: 0, color: '#64748b' }}>{description}</p>
            <p style={{ margin: 0, color: '#94a3b8', fontSize: 13 }}>
              {listing ? `Dossier courant: /${listing.current_path || ''}` : 'Chargement du navigateur VPS...'}
            </p>
          </div>
          <button type="button" className="prospecting-button prospecting-button--ghost" onClick={onClose}>
            Fermer
          </button>
        </header>

        <div className="prospecting-actions">
          <button
            type="button"
            className="prospecting-button prospecting-button--ghost"
            onClick={() => onNavigate(listing?.parent_path ?? '')}
            disabled={!listing || listing.parent_path === null}
          >
            Dossier parent
          </button>
          <button
            type="button"
            className="prospecting-button prospecting-button--ghost"
            onClick={() => onNavigate('')}
            disabled={!listing}
          >
            Racine
          </button>
        </div>

        {errorMessage ? <p className="prospecting-enrichment__error">{errorMessage}</p> : null}

        <div className="prospecting-file-browser">
          {isLoading ? (
            <div className="prospecting-file-browser__empty">Chargement des fichiers...</div>
          ) : !listing || listing.entries.length === 0 ? (
            <div className="prospecting-file-browser__empty">Aucun fichier compatible dans ce dossier.</div>
          ) : (
            listing.entries.map((entry) => (
              <button
                key={`${entry.type}-${entry.path}`}
                type="button"
                className={`prospecting-file-browser__entry prospecting-file-browser__entry--${entry.type}`}
                onClick={() => (entry.type === 'directory' ? onNavigate(entry.path) : onSelectFile(entry))}
                disabled={selectingPath === entry.path}
              >
                <div style={{ display: 'grid', gap: 4, textAlign: 'left' }}>
                  <strong>{entry.name}</strong>
                  <span style={{ color: '#64748b', fontSize: 13 }}>
                    {entry.type === 'directory'
                      ? `Dossier · modifié le ${formatModifiedAt(entry.modified_at)}`
                      : `${entry.extension?.toUpperCase() ?? 'FILE'} · ${formatBytes(entry.size ?? 0)} · ${formatModifiedAt(entry.modified_at)}`}
                  </span>
                </div>
                <span style={{ color: '#0f172a', fontWeight: 800 }}>
                  {entry.type === 'directory' ? 'Ouvrir' : selectingPath === entry.path ? 'Sélection...' : 'Choisir'}
                </span>
              </button>
            ))
          )}
        </div>
      </section>
    </div>
  );
}
