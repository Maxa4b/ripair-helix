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
import { useCsvExplorerJob } from '../hooks/useCsvExplorerJob';
import { useCsvExplorer } from '../hooks/useCsvExplorer';
import { useCsvExplorerRemoteFiles } from '../hooks/useCsvExplorerRemoteFiles';
import '../styles/csv-explorer.css';

const REMOTE_JOB_STORAGE_KEY = 'helix.csvExplorer.remoteJobId';

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
  const [browserOpen, setBrowserOpen] = useState(false);
  const [browserPath, setBrowserPath] = useState('');
  const [selectingRemotePath, setSelectingRemotePath] = useState<string | null>(null);
  const [remoteJobId, setRemoteJobId] = useState<string | null>(() => {
    if (typeof window === 'undefined') {
      return null;
    }

    return window.localStorage.getItem(REMOTE_JOB_STORAGE_KEY);
  });

  const deferredSearch = useDeferredValue(search);
  const deferredColumnValue = useDeferredValue(columnValue);
  const remoteFilesQuery = useCsvExplorerRemoteFiles(browserPath, browserOpen);
  const remoteJobQuery = useCsvExplorerJob(remoteJobId);
  const remoteSnapshot = remoteJobQuery.data?.snapshot ?? null;
  const activeSnapshot = remoteSnapshot ?? snapshot;

  const sourceRows = scope === 'recent' ? activeSnapshot.recentRows : activeSnapshot.previewRows;

  useEffect(() => {
    if (scope === 'recent' && activeSnapshot.recentRows.length === 0 && activeSnapshot.previewRows.length > 0) {
      setScope('preview');
    }
  }, [activeSnapshot.previewRows.length, activeSnapshot.recentRows.length, scope]);

  useEffect(() => {
    if (columnFilter && !activeSnapshot.headers.includes(columnFilter)) {
      setColumnFilter('');
      setColumnValue('');
    }

    if (sort?.column && !activeSnapshot.headers.includes(sort.column)) {
      setSort(null);
    }
  }, [activeSnapshot.headers, columnFilter, sort]);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    if (remoteJobId) {
      window.localStorage.setItem(REMOTE_JOB_STORAGE_KEY, remoteJobId);
      return;
    }

    window.localStorage.removeItem(REMOTE_JOB_STORAGE_KEY);
  }, [remoteJobId]);

  const filteredRows = useMemo(
    () =>
      applyRowFilters(
        sourceRows,
        activeSnapshot.headers,
        deferredSearch,
        columnFilter,
        deferredColumnValue,
        sort,
      ),
    [activeSnapshot.headers, columnFilter, deferredColumnValue, deferredSearch, sort, sourceRows],
  );

  const handleSelectRemoteFile = async (entry: CsvRemoteEntry) => {
    setSelectingRemotePath(entry.path);

    try {
      const response = await apiClient.post('/csv-explorer/jobs', {
        path: entry.path,
        delimiter: config.delimiter,
        encoding: config.encoding,
      });

      const job = response.data.data;
      setRemoteJobId(job.job_id as string);
      setBrowserOpen(false);
    } catch (error) {
      console.error(error);
      window.alert('Impossible de lancer la lecture serveur du CSV.');
    } finally {
      setSelectingRemotePath(null);
    }
  };

  const handleExport = () => {
    if (filteredRows.length === 0 || activeSnapshot.headers.length === 0) {
      return;
    }

    const csv = Papa.unparse({
      fields: activeSnapshot.headers,
      data: filteredRows.map((row) => row.values),
    });

    const blob = new Blob([csv], {
      type: `text/csv;charset=${config.encoding}`,
    });
    const href = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = href;
    anchor.download = buildExportFileName(activeSnapshot.file?.name, scope);
    anchor.click();
    URL.revokeObjectURL(href);
  };

  const isRemoteMode = remoteSnapshot !== null;
  const canPause = !isRemoteMode && (activeSnapshot.status === 'reading' || activeSnapshot.status === 'analyzing');
  const canResume = !isRemoteMode && activeSnapshot.status === 'paused';
  const canCancel =
    isRemoteMode
      ? Boolean(remoteJobId) && ['queued', 'reading'].includes(remoteJobQuery.data?.status ?? '')
      : activeSnapshot.status === 'reading' || activeSnapshot.status === 'analyzing' || activeSnapshot.status === 'paused';
  const canReset = activeSnapshot.file !== null || activeSnapshot.status !== 'idle';
  const canRestart = Boolean(isRemoteMode ? activeSnapshot.file?.path : activeSnapshot.file);
  const canExport = filteredRows.length > 0 && activeSnapshot.headers.length > 0;

  const handleCancel = async () => {
    if (isRemoteMode && remoteJobId) {
      try {
        await apiClient.post(`/csv-explorer/jobs/${remoteJobId}/cancel`);
        await remoteJobQuery.refetch();
      } catch (error) {
        console.error(error);
        window.alert('Impossible d annuler le job serveur.');
      }
      return;
    }

    cancel();
  };

  const handleReset = async () => {
    if (isRemoteMode) {
      if (remoteJobId && ['queued', 'reading'].includes(remoteJobQuery.data?.status ?? '')) {
        try {
          await apiClient.post(`/csv-explorer/jobs/${remoteJobId}/cancel`);
        } catch (error) {
          console.error(error);
        }
      }

      setRemoteJobId(null);
      return;
    }

    reset();
  };

  const handleRestart = async () => {
    if (isRemoteMode && activeSnapshot.file?.path) {
      try {
        const response = await apiClient.post('/csv-explorer/jobs', {
          path: activeSnapshot.file.path,
          delimiter: config.delimiter,
          encoding: config.encoding,
        });

        const job = response.data.data;
        setRemoteJobId(job.job_id as string);
      } catch (error) {
        console.error(error);
        window.alert('Impossible de relancer la lecture serveur.');
      }
      return;
    }

    restart();
  };

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
        file={activeSnapshot.file}
        status={activeSnapshot.status}
        delimiter={activeSnapshot.delimiter}
        selectedDelimiter={config.delimiter}
        selectedEncoding={config.encoding}
        progress={activeSnapshot.progress}
        bytesProcessed={activeSnapshot.bytesProcessed}
        totalRowsRead={activeSnapshot.totalRowsRead}
        onDelimiterChange={setDelimiter}
        onEncodingChange={setEncoding}
        onPause={pause}
        onResume={resume}
        onCancel={handleCancel}
        onReset={handleReset}
        onRestart={handleRestart}
        onExport={handleExport}
        canPause={canPause}
        canResume={canResume}
        canCancel={canCancel}
        canReset={canReset}
        canRestart={canRestart}
        canExport={canExport}
      />

      <CsvExplorerStats snapshot={activeSnapshot} scope={scope} />

      <CsvFiltersBar
        headers={activeSnapshot.headers}
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
        headers={activeSnapshot.headers}
        rows={filteredRows}
        emptyMessage={
          activeSnapshot.file
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
