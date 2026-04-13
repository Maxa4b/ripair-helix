import {
  buildBufferScopeDescription,
  buildBufferScopeLabel,
  countRetainedRows,
  formatBytes,
  formatInteger,
} from '../../features/csv-explorer/csvExplorerUtils';
import type { CsvBufferScope, CsvExplorerSnapshot } from '../../features/csv-explorer/types';

type CsvExplorerStatsProps = {
  snapshot: CsvExplorerSnapshot;
  scope: CsvBufferScope;
};

export default function CsvExplorerStats({ snapshot, scope }: CsvExplorerStatsProps) {
  return (
    <section className="csv-stats-grid">
      <article className="csv-panel csv-stat-card">
        <p className="csv-section-label">Headers detectes</p>
        <strong>{formatInteger(snapshot.headers.length)}</strong>
        <p className="csv-muted">{snapshot.headers.slice(0, 4).join(' | ') || 'En attente de detection'}</p>
      </article>

      <article className="csv-panel csv-stat-card">
        <p className="csv-section-label">Rows retenues</p>
        <strong>{formatInteger(countRetainedRows(snapshot))}</strong>
        <p className="csv-muted">
          {buildBufferScopeLabel(scope)}: {buildBufferScopeDescription(scope, snapshot)}
        </p>
      </article>

      <article className="csv-panel csv-stat-card">
        <p className="csv-section-label">Anomalies</p>
        <strong>{formatInteger(snapshot.invalidRowCount)}</strong>
        <p className="csv-muted">Lignes invalides ou colonnes incoherentes reperees pendant le flux</p>
      </article>

      <article className="csv-panel csv-stat-card">
        <p className="csv-section-label">Flux lu</p>
        <strong>{formatBytes(snapshot.bytesProcessed)}</strong>
        <p className="csv-muted">{snapshot.file ? `sur ${formatBytes(snapshot.file.size)}` : 'Aucun fichier charge'}</p>
      </article>

      {(snapshot.warning || snapshot.error || snapshot.issues.length > 0) && (
        <article className="csv-panel csv-issues-card">
          <div className="csv-issues-card__header">
            <div>
              <p className="csv-section-label">Warnings et erreurs</p>
              <strong>Etat de lecture</strong>
            </div>
            <span className="csv-issues-card__count">{formatInteger(snapshot.issues.length)}</span>
          </div>

          {snapshot.error ? <p className="csv-banner csv-banner--error">{snapshot.error}</p> : null}
          {snapshot.warning ? <p className="csv-banner csv-banner--warning">{snapshot.warning}</p> : null}

          {snapshot.issues.length > 0 ? (
            <ul className="csv-issues-list">
              {snapshot.issues.map((issue) => (
                <li key={issue.id} className={`csv-issues-list__item csv-issues-list__item--${issue.level}`}>
                  <strong>{issue.code}</strong>
                  <span>{issue.message}</span>
                </li>
              ))}
            </ul>
          ) : null}
        </article>
      )}
    </section>
  );
}
