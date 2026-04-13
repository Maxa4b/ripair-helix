import { startTransition, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  appendIssues,
  createInitialCsvSnapshot,
  DEFAULT_CSV_EXPLORER_CONFIG,
  detectFrontLimitWarning,
  mergePreviewRows,
  mergeRecentRows,
} from '../features/csv-explorer/csvExplorerUtils';
import type {
  CsvDelimiterOption,
  CsvEncoding,
  CsvExplorerConfig,
  CsvFileInfo,
  CsvExplorerSnapshot,
  CsvWorkerEvent,
} from '../features/csv-explorer/types';

const FLUSH_INTERVAL_MS = 90;
const STORAGE_KEY = 'helix.csvExplorer.session.v1';
const PERSISTED_PREVIEW_ROWS = 300;
const PERSISTED_RECENT_ROWS = 1200;

type PersistedRemotePayload = {
  url: string;
  fileInfo: CsvFileInfo;
};

type PersistedCsvExplorerSession = {
  config: CsvExplorerConfig;
  snapshot: CsvExplorerSnapshot;
  remotePayload: PersistedRemotePayload | null;
  savedAt: number;
};

function hasWindowStorage() {
  return typeof window !== 'undefined' && typeof window.sessionStorage !== 'undefined';
}

function buildPersistedWarning(snapshot: CsvExplorerSnapshot): string | null {
  if (!snapshot.file) {
    return snapshot.warning;
  }

  const baseWarning = snapshot.warning ? `${snapshot.warning} ` : '';

  if (snapshot.file.source === 'remote') {
    return `${baseWarning}Session restauree apres actualisation. Clique sur Relancer pour reprendre la lecture du fichier VPS.`;
  }

  return `${baseWarning}Session locale restauree apres actualisation. Pour reprendre la lecture, re-selectionne le fichier local.`;
}

function restorePersistedSession():
  | {
      config: CsvExplorerConfig;
      snapshot: CsvExplorerSnapshot;
      remotePayload: PersistedRemotePayload | null;
    }
  | null {
  if (!hasWindowStorage()) {
    return null;
  }

  try {
    const raw = window.sessionStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as PersistedCsvExplorerSession;
    if (!parsed || typeof parsed !== 'object') {
      return null;
    }

    const baseSnapshot = createInitialCsvSnapshot();
    const restoredSnapshot: CsvExplorerSnapshot = {
      ...baseSnapshot,
      ...parsed.snapshot,
      file: parsed.snapshot?.file ?? null,
      headers: Array.isArray(parsed.snapshot?.headers) ? parsed.snapshot.headers : [],
      previewRows: Array.isArray(parsed.snapshot?.previewRows) ? parsed.snapshot.previewRows : [],
      recentRows: Array.isArray(parsed.snapshot?.recentRows) ? parsed.snapshot.recentRows : [],
      issues: Array.isArray(parsed.snapshot?.issues) ? parsed.snapshot.issues : [],
    };

    if (restoredSnapshot.file && ['reading', 'analyzing', 'paused'].includes(restoredSnapshot.status)) {
      restoredSnapshot.status = 'paused';
      restoredSnapshot.warning = buildPersistedWarning(restoredSnapshot);
    }

    return {
      config: parsed.config ?? DEFAULT_CSV_EXPLORER_CONFIG,
      snapshot: restoredSnapshot,
      remotePayload: parsed.remotePayload ?? null,
    };
  } catch (error) {
    console.warn('Impossible de restaurer la session CSV Explorer.', error);
    return null;
  }
}

export function useCsvExplorer() {
  const restoredSession = restorePersistedSession();
  const workerRef = useRef<Worker | null>(null);
  const sessionIdRef = useRef(0);
  const flushTimerRef = useRef<number | null>(null);
  const pendingEventsRef = useRef<CsvWorkerEvent[]>([]);
  const activeFileRef = useRef<File | null>(null);
  const lastRemotePayloadRef = useRef<{
    url: string;
    fileInfo: CsvFileInfo;
    requestHeaders?: Record<string, string>;
  } | null>(
    restoredSession?.remotePayload
      ? {
          ...restoredSession.remotePayload,
          requestHeaders:
            typeof window !== 'undefined' && localStorage.getItem('helixToken')
              ? {
                  Authorization: `Bearer ${localStorage.getItem('helixToken')}`,
                }
              : undefined,
        }
      : null,
  );

  const [config, setConfig] = useState<CsvExplorerConfig>(
    restoredSession?.config ?? DEFAULT_CSV_EXPLORER_CONFIG,
  );
  const [snapshot, setSnapshot] = useState<CsvExplorerSnapshot>(
    restoredSession?.snapshot ?? createInitialCsvSnapshot(),
  );
  const configRef = useRef(config);

  useEffect(() => {
    configRef.current = config;
  }, [config]);

  useEffect(() => {
    if (!hasWindowStorage()) {
      return;
    }

    try {
      const remotePayload = lastRemotePayloadRef.current
        ? {
            url: lastRemotePayloadRef.current.url,
            fileInfo: lastRemotePayloadRef.current.fileInfo,
          }
        : null;

      const persistedSnapshot: CsvExplorerSnapshot = {
        ...snapshot,
        previewRows: snapshot.previewRows.slice(0, PERSISTED_PREVIEW_ROWS),
        recentRows: snapshot.recentRows.slice(-PERSISTED_RECENT_ROWS),
      };

      const payload: PersistedCsvExplorerSession = {
        config,
        snapshot: persistedSnapshot,
        remotePayload,
        savedAt: Date.now(),
      };

      window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    } catch (error) {
      console.warn('Impossible de persister la session CSV Explorer.', error);
    }
  }, [config, snapshot]);

  const controls = useMemo(
    () => ({
      setDelimiter: (delimiter: CsvDelimiterOption) =>
        setConfig((current) => ({
          ...current,
          delimiter,
        })),
      setEncoding: (encoding: CsvEncoding) =>
        setConfig((current) => ({
          ...current,
          encoding,
        })),
    }),
    [],
  );

  const flushPendingEvents = useCallback(() => {
    if (flushTimerRef.current !== null) {
      window.clearTimeout(flushTimerRef.current);
      flushTimerRef.current = null;
    }

    const queued = pendingEventsRef.current.splice(0);
    if (queued.length === 0) {
      return;
    }

    startTransition(() => {
      setSnapshot((current): CsvExplorerSnapshot => {
        const nextSnapshot = queued.reduce<CsvExplorerSnapshot>((draft, message) => {
          if (message.type !== 'chunk') {
            return draft;
          }

          const headers = message.headers && message.headers.length > 0 ? message.headers : draft.headers;
          const previewRows = mergePreviewRows(draft.previewRows, message.rows, configRef.current.previewRowLimit);
          const recentRows = mergeRecentRows(draft.recentRows, message.rows, configRef.current.recentRowLimit);
          const issues = appendIssues(draft.issues, message.issues, configRef.current.issueLimit);

          return {
            ...draft,
            headers,
            previewRows,
            recentRows,
            delimiter: message.delimiter ?? draft.delimiter,
            totalRowsRead: message.totalRowsRead,
            bytesProcessed: message.bytesProcessed,
            progress: message.progress,
            invalidRowCount: message.invalidRowCount,
            issues,
            status: headers.length > 0 ? 'reading' : 'analyzing',
            warning: null,
          };
        }, current);

        return {
          ...nextSnapshot,
          warning: detectFrontLimitWarning(nextSnapshot, configRef.current),
        };
      });
    });
  }, []);

  const scheduleFlush = useCallback(() => {
    if (flushTimerRef.current !== null) {
      return;
    }

    flushTimerRef.current = window.setTimeout(() => {
      flushPendingEvents();
    }, FLUSH_INTERVAL_MS);
  }, [flushPendingEvents]);

  const handleImmediateMessage = useCallback((message: CsvWorkerEvent) => {
    switch (message.type) {
      case 'started':
        setSnapshot({
          ...createInitialCsvSnapshot(),
          file: message.file,
          status: 'analyzing',
          startedAt: Date.now(),
        });
        break;
      case 'paused':
        setSnapshot((current) => ({
          ...current,
          status: 'paused',
        }));
        break;
      case 'resumed':
        setSnapshot((current) => ({
          ...current,
          status: current.headers.length > 0 ? 'reading' : 'analyzing',
        }));
        break;
      case 'completed':
        setSnapshot((current) => {
          const issues = appendIssues(current.issues, message.issues, configRef.current.issueLimit);
          const nextSnapshot = {
            ...current,
            status: 'ready' as const,
            delimiter: message.delimiter ?? current.delimiter,
            totalRowsRead: message.totalRowsRead,
            bytesProcessed: message.bytesProcessed,
            progress: 1,
            invalidRowCount: message.invalidRowCount,
            issues,
            completedAt: Date.now(),
          };

          return {
            ...nextSnapshot,
            warning: detectFrontLimitWarning(nextSnapshot, configRef.current),
          };
        });
        break;
      case 'aborted':
        setSnapshot((current) => ({
          ...current,
          status: current.file ? 'cancelled' : 'idle',
          error: null,
          progress: current.status === 'ready' ? current.progress : 0,
        }));
        break;
      case 'error':
        setSnapshot((current) => ({
          ...current,
          status: 'error',
          error: message.message,
          issues: appendIssues(current.issues, message.issues, configRef.current.issueLimit),
          completedAt: Date.now(),
        }));
        break;
      case 'chunk':
        break;
    }
  }, []);

  useEffect(() => {
    const worker = new Worker(new URL('../workers/csvParser.worker.ts', import.meta.url), {
      type: 'module',
    });

    worker.onmessage = (event: MessageEvent<CsvWorkerEvent>) => {
      const message = event.data;
      if (message.sessionId !== sessionIdRef.current) {
        return;
      }

      if (message.type === 'chunk') {
        pendingEventsRef.current.push(message);
        scheduleFlush();
        return;
      }

      flushPendingEvents();
      handleImmediateMessage(message);
    };

    workerRef.current = worker;

    return () => {
      worker.terminate();
      workerRef.current = null;

      if (flushTimerRef.current !== null) {
        window.clearTimeout(flushTimerRef.current);
      }
    };
  }, [flushPendingEvents, handleImmediateMessage, scheduleFlush]);

  const startParsing = (file: File) => {
    const worker = workerRef.current;
    if (!worker) {
      return;
    }

    if (sessionIdRef.current > 0) {
      worker.postMessage({
        type: 'abort',
        sessionId: sessionIdRef.current,
      });
    }

    activeFileRef.current = file;
    lastRemotePayloadRef.current = null;
    pendingEventsRef.current = [];
    sessionIdRef.current += 1;

    worker.postMessage({
      type: 'start',
      sessionId: sessionIdRef.current,
      payload: {
        source: {
          kind: 'local',
          file,
        },
        config,
      },
    });
  };

  const startRemoteParsing = (payload: {
    url: string;
    fileInfo: CsvFileInfo;
    requestHeaders?: Record<string, string>;
  }) => {
    const worker = workerRef.current;
    if (!worker) {
      return;
    }

    if (sessionIdRef.current > 0) {
      worker.postMessage({
        type: 'abort',
        sessionId: sessionIdRef.current,
      });
    }

    activeFileRef.current = null;
    lastRemotePayloadRef.current = payload;
    pendingEventsRef.current = [];
    sessionIdRef.current += 1;

    worker.postMessage({
      type: 'start',
      sessionId: sessionIdRef.current,
      payload: {
        source: {
          kind: 'remote',
          url: payload.url,
          fileInfo: payload.fileInfo,
          requestHeaders: payload.requestHeaders,
        },
        config,
      },
    });
  };

  const pause = () => {
    if (!workerRef.current || !activeFileRef.current) {
      return;
    }

    workerRef.current.postMessage({
      type: 'pause',
      sessionId: sessionIdRef.current,
    });
  };

  const resume = () => {
    if (!workerRef.current || !activeFileRef.current) {
      return;
    }

    workerRef.current.postMessage({
      type: 'resume',
      sessionId: sessionIdRef.current,
    });
  };

  const cancel = () => {
    if (!workerRef.current) {
      return;
    }

    pendingEventsRef.current = [];
    workerRef.current.postMessage({
      type: 'abort',
      sessionId: sessionIdRef.current,
    });
  };

  const reset = () => {
    if (workerRef.current && sessionIdRef.current > 0) {
      workerRef.current.postMessage({
        type: 'abort',
        sessionId: sessionIdRef.current,
      });
    }

    sessionIdRef.current += 1;
    activeFileRef.current = null;
    lastRemotePayloadRef.current = null;
    pendingEventsRef.current = [];
    setSnapshot(createInitialCsvSnapshot());

    if (hasWindowStorage()) {
      window.sessionStorage.removeItem(STORAGE_KEY);
    }
  };

  const restart = () => {
    if (activeFileRef.current) {
      startParsing(activeFileRef.current);
      return;
    }

    if (lastRemotePayloadRef.current) {
      startRemoteParsing(lastRemotePayloadRef.current);
    }
  };

  return {
    config,
    snapshot,
    openFile: startParsing,
    openRemoteFile: startRemoteParsing,
    pause,
    resume,
    cancel,
    reset,
    restart,
    ...controls,
  };
}
