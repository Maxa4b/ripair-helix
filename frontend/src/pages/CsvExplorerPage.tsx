import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import Papa from 'papaparse';
import CsvExplorerStats from '../components/csv-explorer/CsvExplorerStats';
import CsvExplorerToolbar from '../components/csv-explorer/CsvExplorerToolbar';
import CsvFilePicker from '../components/csv-explorer/CsvFilePicker';
import CsvFiltersBar from '../components/csv-explorer/CsvFiltersBar';
import CsvVirtualTable from '../components/csv-explorer/CsvVirtualTable';
import { applyRowFilters } from '../features/csv-explorer/csvExplorerUtils';
import type { CsvBufferScope, CsvSortState } from '../features/csv-explorer/types';
import { useCsvExplorer } from '../hooks/useCsvExplorer';
import '../styles/csv-explorer.css';

function buildExportFileName(fileName: string | undefined, scope: CsvBufferScope) {
  const baseName = (fileName ?? 'csv-explorer').replace(/\.[^.]+$/, '');
  return `${baseName}-${scope}-subset.csv`;
}

export default function CsvExplorerPage() {
  const {
    config,
    snapshot,
    openFile,
    pause,
    resume,
    cancel,
    reset,
    restart,
    setDelimiter,
    setEncoding,
  } = useCsvExplorer();

  const [scope, setScope] = useState<CsvBufferScope>('preview');
  const [search, setSearch] = useState('');
  const [columnFilter, setColumnFilter] = useState('');
  const [columnValue, setColumnValue] = useState('');
  const [sort, setSort] = useState<CsvSortState | null>(null);

  const deferredSearch = useDeferredValue(search);
  const deferredColumnValue = useDeferredValue(columnValue);

  const sourceRows = scope === 'recent' ? snapshot.recentRows : snapshot.previewRows;

  useEffect(() => {
    if (scope === 'recent' && snapshot.recentRows.length === 0 && snapshot.previewRows.length > 0) {
      setScope('preview');
    }
  }, [scope, snapshot.previewRows.length, snapshot.recentRows.length]);

  useEffect(() => {
    if (columnFilter && !snapshot.headers.includes(columnFilter)) {
      setColumnFilter('');
      setColumnValue('');
    }

    if (sort?.column && !snapshot.headers.includes(sort.column)) {
      setSort(null);
    }
  }, [columnFilter, sort, snapshot.headers]);

  const filteredRows = useMemo(
    () =>
      applyRowFilters(
        sourceRows,
        snapshot.headers,
        deferredSearch,
        columnFilter,
        deferredColumnValue,
        sort,
      ),
    [columnFilter, deferredColumnValue, deferredSearch, snapshot.headers, sort, sourceRows],
  );

  const handleExport = () => {
    if (filteredRows.length === 0 || snapshot.headers.length === 0) {
      return;
    }

    const csv = Papa.unparse({
      fields: snapshot.headers,
      data: filteredRows.map((row) => row.values),
    });

    const blob = new Blob([csv], {
      type: `text/csv;charset=${config.encoding}`,
    });
    const href = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = href;
    anchor.download = buildExportFileName(snapshot.file?.name, scope);
    anchor.click();
    URL.revokeObjectURL(href);
  };

  const canPause = snapshot.status === 'reading' || snapshot.status === 'analyzing';
  const canResume = snapshot.status === 'paused';
  const canCancel = snapshot.status === 'reading' || snapshot.status === 'analyzing' || snapshot.status === 'paused';
  const canReset = snapshot.file !== null || snapshot.status !== 'idle';
  const canRestart = snapshot.file !== null;
  const canExport = filteredRows.length > 0 && snapshot.headers.length > 0;

  return (
    <div className="csv-page">
      <CsvFilePicker
        disabled={snapshot.status === 'reading' || snapshot.status === 'analyzing'}
        currentFileName={snapshot.file?.name}
        onFileSelected={openFile}
      />

      <section className="csv-panel csv-architecture-note">
        <div>
          <p className="csv-section-label">Mode retenu</p>
          <strong>Mode A - Front streaming avec worker</strong>
        </div>
        <p className="csv-muted">
          Helix dispose deja d&apos;un backend Laravel, mais pas encore d&apos;un pipeline local de pagination CSV
          native. Cette version n&apos;uploade rien : elle parse localement, garde un buffer borne et reste prete pour
          une future bascule vers un moteur backend ou DuckDB.
        </p>
      </section>

      <CsvExplorerToolbar
        file={snapshot.file}
        status={snapshot.status}
        delimiter={snapshot.delimiter}
        selectedDelimiter={config.delimiter}
        selectedEncoding={config.encoding}
        progress={snapshot.progress}
        bytesProcessed={snapshot.bytesProcessed}
        totalRowsRead={snapshot.totalRowsRead}
        onDelimiterChange={setDelimiter}
        onEncodingChange={setEncoding}
        onPause={pause}
        onResume={resume}
        onCancel={cancel}
        onReset={reset}
        onRestart={restart}
        onExport={handleExport}
        canPause={canPause}
        canResume={canResume}
        canCancel={canCancel}
        canReset={canReset}
        canRestart={canRestart}
        canExport={canExport}
      />

      <CsvExplorerStats snapshot={snapshot} scope={scope} />

      <CsvFiltersBar
        headers={snapshot.headers}
        scope={scope}
        search={search}
        columnFilter={columnFilter}
        columnValue={columnValue}
        sort={sort}
        sourceCount={sourceRows.length}
        filteredCount={filteredRows.length}
        onScopeChange={setScope}
        onSearchChange={setSearch}
        onColumnFilterChange={setColumnFilter}
        onColumnValueChange={setColumnValue}
        onSortChange={setSort}
      />

      <CsvVirtualTable
        headers={snapshot.headers}
        rows={filteredRows}
        emptyMessage={
          snapshot.file
            ? 'Aucune ligne disponible dans la vue courante. Ajuste les filtres, le scope ou le delimitateur.'
            : 'Charge un fichier CSV pour afficher l apercu.'
        }
      />
    </div>
  );
}
