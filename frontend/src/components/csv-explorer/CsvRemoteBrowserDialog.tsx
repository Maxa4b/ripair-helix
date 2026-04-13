import { formatBytes } from '../../features/csv-explorer/csvExplorerUtils';
import type { CsvRemoteEntry, CsvRemoteListing } from '../../features/csv-explorer/types';

type CsvRemoteBrowserDialogProps = {
  open: boolean;
  listing: CsvRemoteListing | undefined;
  isLoading: boolean;
  errorMessage: string | null;
  selectingPath: string | null;
  onClose: () => void;
  onNavigate: (path: string) => void;
  onSelectFile: (entry: CsvRemoteEntry) => void;
};

function formatModifiedAt(value: string) {
  return new Date(value).toLocaleString('fr-FR');
}

export default function CsvRemoteBrowserDialog({
  open,
  listing,
  isLoading,
  errorMessage,
  selectingPath,
  onClose,
  onNavigate,
  onSelectFile,
}: CsvRemoteBrowserDialogProps) {
  if (!open) {
    return null;
  }

  return (
    <div className="csv-dialog-backdrop" role="presentation" onClick={onClose}>
      <section
        className="csv-dialog"
        role="dialog"
        aria-modal="true"
        aria-label="Selectionner un CSV du VPS"
        onClick={(event) => event.stopPropagation()}
      >
        <header className="csv-dialog__header">
          <div>
            <p className="csv-section-label">Fichiers VPS</p>
            <h2 className="csv-dialog__title">{listing?.root.label ?? 'CSV Explorer VPS'}</h2>
            <p className="csv-muted">
              {listing ? `Dossier courant : /${listing.current_path || ''}` : 'Chargement du navigateur de fichiers...'}
            </p>
          </div>
          <button type="button" className="csv-button csv-button--ghost" onClick={onClose}>
            Fermer
          </button>
        </header>

        <div className="csv-dialog__toolbar">
          <button
            type="button"
            className="csv-button csv-button--ghost"
            onClick={() => onNavigate(listing?.parent_path ?? '')}
            disabled={!listing || listing.parent_path === null}
          >
            Dossier parent
          </button>
          <button type="button" className="csv-button csv-button--ghost" onClick={() => onNavigate('')} disabled={!listing}>
            Racine
          </button>
        </div>

        {errorMessage ? <p className="csv-banner csv-banner--error">{errorMessage}</p> : null}

        <div className="csv-browser">
          {isLoading ? (
            <div className="csv-browser__empty">Chargement des fichiers du VPS...</div>
          ) : !listing || listing.entries.length === 0 ? (
            <div className="csv-browser__empty">Aucun CSV detecte dans ce dossier.</div>
          ) : (
            listing.entries.map((entry) => (
              <button
                key={`${entry.type}-${entry.path}`}
                type="button"
                className={`csv-browser__entry csv-browser__entry--${entry.type}`}
                onClick={() => (entry.type === 'directory' ? onNavigate(entry.path) : onSelectFile(entry))}
                disabled={selectingPath === entry.path}
              >
                <div>
                  <strong>{entry.name}</strong>
                  <p className="csv-muted">
                    {entry.type === 'directory'
                      ? `Dossier • modifie le ${formatModifiedAt(entry.modified_at)}`
                      : `${entry.extension?.toUpperCase() ?? 'CSV'} • ${formatBytes(entry.size ?? 0)} • ${formatModifiedAt(entry.modified_at)}`}
                  </p>
                </div>
                <span className="csv-browser__entry-action">
                  {entry.type === 'directory'
                    ? 'Ouvrir'
                    : selectingPath === entry.path
                      ? 'Ouverture...'
                      : 'Charger'}
                </span>
              </button>
            ))
          )}
        </div>
      </section>
    </div>
  );
}
