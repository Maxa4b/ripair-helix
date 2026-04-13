import { useMemo, useRef, useState } from 'react';
import {
  VIRTUAL_OVERSCAN,
  VIRTUAL_ROW_HEIGHT,
  formatInteger,
} from '../../features/csv-explorer/csvExplorerUtils';
import type { CsvRow } from '../../features/csv-explorer/types';

const HEADER_HEIGHT = 48;
const VIEWPORT_HEIGHT = 560;

type CsvVirtualTableProps = {
  headers: string[];
  rows: CsvRow[];
  emptyMessage: string;
};

export default function CsvVirtualTable({ headers, rows, emptyMessage }: CsvVirtualTableProps) {
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const [scrollTop, setScrollTop] = useState(0);

  const gridTemplateColumns = useMemo(
    () => `96px repeat(${Math.max(headers.length, 1)}, minmax(180px, 1fr))`,
    [headers.length],
  );

  const rowScrollTop = Math.max(0, scrollTop - HEADER_HEIGHT);
  const visibleCount = Math.ceil(VIEWPORT_HEIGHT / VIRTUAL_ROW_HEIGHT);
  const startIndex = Math.max(0, Math.floor(rowScrollTop / VIRTUAL_ROW_HEIGHT) - VIRTUAL_OVERSCAN);
  const endIndex = Math.min(rows.length, startIndex + visibleCount + VIRTUAL_OVERSCAN * 2);
  const topSpacerHeight = startIndex * VIRTUAL_ROW_HEIGHT;
  const bottomSpacerHeight = Math.max(0, (rows.length - endIndex) * VIRTUAL_ROW_HEIGHT);
  const visibleRows = rows.slice(startIndex, endIndex);

  return (
    <section className="csv-panel csv-table-panel">
      <div className="csv-table-panel__header">
        <div>
          <p className="csv-section-label">Table virtualisee</p>
          <strong>{formatInteger(rows.length)} lignes dans la vue courante</strong>
        </div>
        <p className="csv-muted">Scroll vertical virtualise, header sticky, rendu borne aux lignes visibles.</p>
      </div>

      <div
        ref={scrollRef}
        className="csv-table-scroll"
        style={{ maxHeight: VIEWPORT_HEIGHT }}
        onScroll={(event) => setScrollTop(event.currentTarget.scrollTop)}
      >
        <div className="csv-table-row csv-table-row--header" style={{ gridTemplateColumns }}>
          <div className="csv-table-cell csv-table-cell--header">#</div>
          {headers.map((header) => (
            <div key={header} className="csv-table-cell csv-table-cell--header" title={header}>
              {header}
            </div>
          ))}
        </div>

        {rows.length === 0 ? (
          <div className="csv-table-empty">{emptyMessage}</div>
        ) : (
          <>
            <div style={{ height: topSpacerHeight }} />
            {visibleRows.map((row) => (
              <div
                key={row.id}
                className={`csv-table-row${row.rowNumber % 2 === 0 ? ' csv-table-row--alt' : ''}`}
                style={{ gridTemplateColumns }}
              >
                <div className="csv-table-cell csv-table-cell--index">{row.rowNumber + 1}</div>
                {headers.map((header, columnIndex) => (
                  <div
                    key={`${row.id}-${header}`}
                    className="csv-table-cell"
                    title={row.values[columnIndex] ?? ''}
                  >
                    {row.values[columnIndex] ?? ''}
                  </div>
                ))}
              </div>
            ))}
            <div style={{ height: bottomSpacerHeight }} />
          </>
        )}
      </div>
    </section>
  );
}
