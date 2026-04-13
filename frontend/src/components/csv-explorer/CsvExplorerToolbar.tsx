import { formatBytes, formatInteger, formatProgress } from '../../features/csv-explorer/csvExplorerUtils';
import type {
  CsvDelimiterOption,
  CsvEncoding,
  CsvExplorerStatus,
  CsvFileInfo,
} from '../../features/csv-explorer/types';

type CsvExplorerToolbarProps = {
  file: CsvFileInfo | null;
  status: CsvExplorerStatus;
  delimiter: string | null;
  selectedDelimiter: CsvDelimiterOption;
  selectedEncoding: CsvEncoding;
  progress: number;
  bytesProcessed: number;
  totalRowsRead: number;
  onDelimiterChange: (value: CsvDelimiterOption) => void;
  onEncodingChange: (value: CsvEncoding) => void;
  onPause: () => void;
  onResume: () => void;
  onCancel: () => void;
  onReset: () => void;
  onRestart: () => void;
  onExport: () => void;
  canPause: boolean;
  canResume: boolean;
  canCancel: boolean;
  canReset: boolean;
  canRestart: boolean;
  canExport: boolean;
};

const STATUS_LABELS: Record<CsvExplorerStatus, string> = {
  idle: 'Inactif',
  analyzing: 'Analyse',
  reading: 'Lecture',
  paused: 'Pause',
  ready: 'Pret',
  cancelled: 'Annule',
  error: 'Erreur',
};

const DELIMITER_OPTIONS: Array<{ value: CsvDelimiterOption; label: string }> = [
  { value: 'auto', label: 'Auto' },
  { value: ';', label: 'Point-virgule' },
  { value: ',', label: 'Virgule' },
  { value: '\t', label: 'Tabulation' },
  { value: '|', label: 'Pipe' },
];

const ENCODING_OPTIONS: Array<{ value: CsvEncoding; label: string }> = [
  { value: 'utf-8', label: 'UTF-8' },
  { value: 'windows-1252', label: 'Windows-1252' },
  { value: 'iso-8859-1', label: 'ISO-8859-1' },
];

export default function CsvExplorerToolbar({
  file,
  status,
  delimiter,
  selectedDelimiter,
  selectedEncoding,
  progress,
  bytesProcessed,
  totalRowsRead,
  onDelimiterChange,
  onEncodingChange,
  onPause,
  onResume,
  onCancel,
  onReset,
  onRestart,
  onExport,
  canPause,
  canResume,
  canCancel,
  canReset,
  canRestart,
  canExport,
}: CsvExplorerToolbarProps) {
  return (
    <section className="csv-panel csv-toolbar">
      <div className="csv-toolbar__meta">
        <div>
          <p className="csv-section-label">Etat</p>
          <div className={`csv-status-badge csv-status-badge--${status}`}>{STATUS_LABELS[status]}</div>
        </div>

        <div>
          <p className="csv-section-label">Fichier</p>
          <strong>{file?.name ?? 'Aucun fichier'}</strong>
          <p className="csv-toolbar__meta-line">{file ? formatBytes(file.size) : 'Pret a charger'}</p>
        </div>

        <div>
          <p className="csv-section-label">Progression</p>
          <strong>{formatProgress(progress)}</strong>
          <p className="csv-toolbar__meta-line">
            {formatBytes(bytesProcessed)} lus, {formatInteger(totalRowsRead)} lignes
          </p>
        </div>

        <div>
          <p className="csv-section-label">Separateur</p>
          <strong>{delimiter === '\t' ? 'Tabulation' : delimiter ?? 'Auto'}</strong>
          <p className="csv-toolbar__meta-line">Detection en cours si Auto</p>
        </div>
      </div>

      <div className="csv-toolbar__controls">
        <label className="csv-field">
          Encodage
          <select value={selectedEncoding} onChange={(event) => onEncodingChange(event.target.value as CsvEncoding)}>
            {ENCODING_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>

        <label className="csv-field">
          Separateur
          <select
            value={selectedDelimiter}
            onChange={(event) => onDelimiterChange(event.target.value as CsvDelimiterOption)}
          >
            {DELIMITER_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>

        <div className="csv-toolbar__buttons">
          <button type="button" className="csv-button csv-button--ghost" onClick={onPause} disabled={!canPause}>
            Pause
          </button>
          <button type="button" className="csv-button csv-button--ghost" onClick={onResume} disabled={!canResume}>
            Reprendre
          </button>
          <button type="button" className="csv-button csv-button--ghost" onClick={onRestart} disabled={!canRestart}>
            Relancer
          </button>
          <button type="button" className="csv-button csv-button--danger" onClick={onCancel} disabled={!canCancel}>
            Annuler
          </button>
          <button type="button" className="csv-button csv-button--ghost" onClick={onReset} disabled={!canReset}>
            Reinitialiser
          </button>
          <button type="button" className="csv-button csv-button--primary" onClick={onExport} disabled={!canExport}>
            Exporter
          </button>
        </div>
      </div>
    </section>
  );
}
