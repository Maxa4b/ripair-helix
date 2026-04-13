import type { CsvBufferScope, CsvSortState } from '../../features/csv-explorer/types';

type CsvFiltersBarProps = {
  headers: string[];
  scope: CsvBufferScope;
  search: string;
  columnFilter: string;
  columnValue: string;
  sort: CsvSortState | null;
  sourceCount: number;
  filteredCount: number;
  onScopeChange: (scope: CsvBufferScope) => void;
  onSearchChange: (value: string) => void;
  onColumnFilterChange: (value: string) => void;
  onColumnValueChange: (value: string) => void;
  onSortChange: (sort: CsvSortState | null) => void;
};

export default function CsvFiltersBar({
  headers,
  scope,
  search,
  columnFilter,
  columnValue,
  sort,
  sourceCount,
  filteredCount,
  onScopeChange,
  onSearchChange,
  onColumnFilterChange,
  onColumnValueChange,
  onSortChange,
}: CsvFiltersBarProps) {
  return (
    <section className="csv-panel csv-filters">
      <div className="csv-filters__row">
        <div className="csv-segmented">
          <button
            type="button"
            className={`csv-segmented__button${scope === 'preview' ? ' csv-segmented__button--active' : ''}`}
            onClick={() => onScopeChange('preview')}
          >
            Apercu initial
          </button>
          <button
            type="button"
            className={`csv-segmented__button${scope === 'recent' ? ' csv-segmented__button--active' : ''}`}
            onClick={() => onScopeChange('recent')}
          >
            Tampon recent
          </button>
        </div>

        <p className="csv-muted">
          {filteredCount} lignes affichees sur {sourceCount} retenues dans cette vue
        </p>
      </div>

      <div className="csv-filters__grid">
        <label className="csv-field">
          Recherche globale
          <input
            value={search}
            onChange={(event) => onSearchChange(event.target.value)}
            placeholder="Nom, SIREN, ville, code..."
          />
        </label>

        <label className="csv-field">
          Filtre colonne
          <select value={columnFilter} onChange={(event) => onColumnFilterChange(event.target.value)}>
            <option value="">Aucune colonne</option>
            {headers.map((header) => (
              <option key={header} value={header}>
                {header}
              </option>
            ))}
          </select>
        </label>

        <label className="csv-field">
          Valeur du filtre
          <input
            value={columnValue}
            onChange={(event) => onColumnValueChange(event.target.value)}
            placeholder="Contient..."
            disabled={!columnFilter}
          />
        </label>

        <label className="csv-field">
          Tri
          <select
            value={sort?.column ?? ''}
            onChange={(event) => {
              const column = event.target.value;
              onSortChange(column ? { column, direction: sort?.direction ?? 'asc' } : null);
            }}
          >
            <option value="">Ordre du buffer</option>
            {headers.map((header) => (
              <option key={header} value={header}>
                {header}
              </option>
            ))}
          </select>
        </label>

        <label className="csv-field">
          Direction
          <select
            value={sort?.direction ?? 'asc'}
            onChange={(event) => {
              if (!sort?.column) {
                return;
              }

              onSortChange({
                column: sort.column,
                direction: event.target.value as 'asc' | 'desc',
              });
            }}
            disabled={!sort?.column}
          >
            <option value="asc">Ascendant</option>
            <option value="desc">Descendant</option>
          </select>
        </label>

        <div className="csv-filters__clear">
          <button type="button" className="csv-button csv-button--ghost" onClick={() => onSortChange(null)}>
            Reinitialiser le tri
          </button>
        </div>
      </div>
    </section>
  );
}
