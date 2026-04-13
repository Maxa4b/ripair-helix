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
  CsvExplorerSnapshot,
  CsvWorkerEvent,
} from '../features/csv-explorer/types';

const FLUSH_INTERVAL_MS = 90;

export function useCsvExplorer() {
  const workerRef = useRef<Worker | null>(null);
  const sessionIdRef = useRef(0);
  const flushTimerRef = useRef<number | null>(null);
  const pendingEventsRef = useRef<CsvWorkerEvent[]>([]);
  const activeFileRef = useRef<File | null>(null);

  const [config, setConfig] = useState<CsvExplorerConfig>(DEFAULT_CSV_EXPLORER_CONFIG);
  const [snapshot, setSnapshot] = useState<CsvExplorerSnapshot>(createInitialCsvSnapshot);
  const configRef = useRef(config);

  useEffect(() => {
    configRef.current = config;
  }, [config]);

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
    pendingEventsRef.current = [];
    sessionIdRef.current += 1;

    worker.postMessage({
      type: 'start',
      sessionId: sessionIdRef.current,
      payload: {
        file,
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
    pendingEventsRef.current = [];
    setSnapshot(createInitialCsvSnapshot());
  };

  const restart = () => {
    if (!activeFileRef.current) {
      return;
    }

    startParsing(activeFileRef.current);
  };

  return {
    config,
    snapshot,
    openFile: startParsing,
    pause,
    resume,
    cancel,
    reset,
    restart,
    ...controls,
  };
}
