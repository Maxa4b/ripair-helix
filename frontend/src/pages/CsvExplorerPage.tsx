import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import Papa from 'papaparse';
import CsvExplorerStats from '../components/csv-explorer/CsvExplorerStats';
import CsvExplorerToolbar from '../components/csv-explorer/CsvExplorerToolbar';
import CsvFilePicker from '../components/csv-explorer/CsvFilePicker';
import CsvFiltersBar from '../components/csv-explorer/CsvFiltersBar';
import CsvRemoteBrowserDialog from '../components/csv-explorer/CsvRemoteBrowserDialog';
import CsvVirtualTable from '../components/csv-explorer/CsvVirtualTable';
import apiClient from '../api/client';
import { applyRowFilters } from '../features/csv-explorer/csvExplorerUtils';
import type { CsvBufferScope, CsvRemoteEntry, CsvSortState } from '../features/csv-explorer/types';
import { useCsvExplorer } from '../hooks/useCsvExplorer';
import { useCsvExplorerRemoteFiles } from '../hooks/useCsvExplorerRemoteFiles';
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
    openRemoteFile,
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
  const [browserOpen, setBrowserOpen] = useState(false);
  const [browserPath, setBrowserPath] = useState('');
  const [selectingRemotePath, setSelectingRemotePath] = useState<string | null>(null);

  const deferredSearch = useDeferredValue(search);
  const deferredColumnValue = useDeferredValue(columnValue);
  const remoteFilesQuery = useCsvExplorerRemoteFiles(browserPath, browserOpen);

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

  const handleSelectRemoteFile = (entry: CsvRemoteEntry) => {
    const token = localStorage.getItem('helixToken');
    setSelectingRemotePath(entry.path);

    openRemoteFile({
      url: apiClient.getUri({
        url: '/csv-explorer/stream',
        params: { path: entry.path },
      }),
      fileInfo: {
        name: entry.name,
        size: entry.size ?? 0,
        type: 'text/csv',
        lastModified: Date.parse(entry.modified_at) || Date.now(),
        source: 'remote',
        path: entry.path,
      },
      requestHeaders:
        token && token !== 'undefined' && token !== 'null'
          ? {
              Authorization: `Bearer ${token}`,
            }
          : undefined,
    });

    setBrowserOpen(false);
    setSelectingRemotePath(null);
  };

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
        onOpenRemoteBrowser={() => setBrowserOpen(true)}
        onLocalFileSelected={openFile}
      />

      <section className="csv-panel csv-architecture-note">
        <div>
          <p className="csv-section-label">Mode retenu</p>
          <strong>Mode hybride - Browser VPS + parsing streaming dans le front</strong>
        </div>
        <p className="csv-muted">
          Le bouton Ouvrir passe maintenant en priorite par une fenetre listant les CSV du VPS via l API Laravel.
          Le parsing reste hors thread UI avec un buffer borne, sans charger tout le fichier en memoire.
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

      <CsvRemoteBrowserDialog
        open={browserOpen}
        listing={remoteFilesQuery.data}
        isLoading={remoteFilesQuery.isLoading}
        errorMessage={remoteFilesQuery.isError ? 'Impossible de charger les fichiers du VPS.' : null}
        selectingPath={selectingRemotePath}
        onClose={() => {
          setBrowserOpen(false);
          setSelectingRemotePath(null);
        }}
        onNavigate={setBrowserPath}
        onSelectFile={handleSelectRemoteFile}
      />
    </div>
  );
}
