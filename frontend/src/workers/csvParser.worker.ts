/// <reference lib="webworker" />

import Papa, { type ParseError, type ParseMeta, type Parser } from 'papaparse';
import type {
  CsvExplorerConfig,
  CsvParserIssue,
  CsvRow,
  CsvWorkerCommand,
  CsvWorkerEvent,
} from '../features/csv-explorer/types';

const ctx: DedicatedWorkerGlobalScope = self as DedicatedWorkerGlobalScope;

let activeSessionId = 0;
let activeParser: Parser | null = null;

ctx.onmessage = (event: MessageEvent<CsvWorkerCommand>) => {
  const message = event.data;

  switch (message.type) {
    case 'start':
      if (activeParser) {
        activeParser.abort();
        activeParser = null;
      }
      activeSessionId = message.sessionId;
      startParsing(message.sessionId, message.payload.file, message.payload.config);
      break;
    case 'pause':
      if (message.sessionId === activeSessionId && activeParser) {
        activeParser.pause();
        postMessageSafe({
          type: 'paused',
          sessionId: message.sessionId,
        });
      }
      break;
    case 'resume':
      if (message.sessionId === activeSessionId && activeParser) {
        activeParser.resume();
        postMessageSafe({
          type: 'resumed',
          sessionId: message.sessionId,
        });
      }
      break;
    case 'abort':
      if (message.sessionId === activeSessionId && activeParser) {
        activeParser.abort();
        activeParser = null;
      }

      if (message.sessionId === activeSessionId) {
        postMessageSafe({
          type: 'aborted',
          sessionId: message.sessionId,
        });
      }
      break;
  }
};

function startParsing(sessionId: number, file: File, config: CsvExplorerConfig) {
  let headers: string[] | null = null;
  let totalRowsRead = 0;
  let invalidRowCount = 0;
  let delimiter: string | null = config.delimiter === 'auto' ? null : config.delimiter;

  postMessageSafe({
    type: 'started',
    sessionId,
    file: {
      name: file.name,
      size: file.size,
      type: file.type,
      lastModified: file.lastModified,
    },
  });

  Papa.parse<string[]>(file, {
    delimiter: config.delimiter === 'auto' ? '' : config.delimiter,
    dynamicTyping: false,
    worker: false,
    skipEmptyLines: 'greedy',
    encoding: config.encoding,
    chunkSize: config.chunkSize,
    chunk(results, parser) {
      if (sessionId !== activeSessionId) {
        parser.abort();
        return;
      }

      activeParser = parser;
      delimiter = results.meta.delimiter || delimiter;

      const issues = normalizeIssues(results.errors);
      invalidRowCount += results.errors.length;

      const rawRows = Array.isArray(results.data) ? results.data : [];
      const chunkRows: CsvRow[] = [];
      let chunkHeaders: string[] | undefined;

      if (!headers) {
        const headerIndex = rawRows.findIndex((row) => hasAnyCell(row));

        if (headerIndex === -1) {
          postChunkMessage(
            sessionId,
            [],
            undefined,
            delimiter,
            totalRowsRead,
            results.meta,
            file.size,
            invalidRowCount,
            issues,
          );
          return;
        }

        headers = sanitizeHeaders(rawRows[headerIndex]);
        chunkHeaders = headers;
        const normalized = normalizeRows(rawRows.slice(headerIndex + 1), headers, totalRowsRead + 1);
        totalRowsRead += normalized.rows.length;
        invalidRowCount += normalized.issues.length;
        chunkRows.push(...normalized.rows);
        issues.push(...normalized.issues);
      } else {
        const normalized = normalizeRows(rawRows, headers, totalRowsRead + 1);
        totalRowsRead += normalized.rows.length;
        invalidRowCount += normalized.issues.length;
        chunkRows.push(...normalized.rows);
        issues.push(...normalized.issues);
      }

      postChunkMessage(
        sessionId,
        chunkRows,
        chunkHeaders,
        delimiter,
        totalRowsRead,
        results.meta,
        file.size,
        invalidRowCount,
        issues,
      );
    },
    complete() {
      if (sessionId !== activeSessionId) {
        return;
      }

      activeParser = null;

      if (!headers) {
        postMessageSafe({
          type: 'error',
          sessionId,
          message: 'Le fichier est vide ou ne contient aucune ligne de colonnes exploitable.',
          issues: [
            {
              id: `empty-${sessionId}`,
              level: 'error',
              code: 'empty_file',
              message: 'Aucun header détecté dans le fichier.',
            },
          ],
        });
        return;
      }

      postMessageSafe({
        type: 'completed',
        sessionId,
        delimiter,
        totalRowsRead,
        bytesProcessed: file.size,
        invalidRowCount,
        issues: [],
      });
    },
    error(error) {
      if (sessionId !== activeSessionId) {
        return;
      }

      activeParser = null;

      postMessageSafe({
        type: 'error',
        sessionId,
        message: error.message || 'Erreur inattendue pendant la lecture du CSV.',
        issues: [
          {
            id: `fatal-${sessionId}`,
            level: 'error',
            code: 'fatal_parse_error',
            message: error.message || 'Erreur inattendue pendant la lecture du CSV.',
          },
        ],
      });
    },
  });
}

function postChunkMessage(
  sessionId: number,
  rows: CsvRow[],
  headers: string[] | undefined,
  delimiter: string | null,
  totalRowsRead: number,
  meta: ParseMeta,
  fileSize: number,
  invalidRowCount: number,
  issues: CsvParserIssue[],
) {
  const cursor = typeof meta.cursor === 'number' ? meta.cursor : 0;
  const boundedCursor = Math.min(fileSize, Math.max(0, cursor));
  const progress = fileSize > 0 ? Math.min(1, boundedCursor / fileSize) : 0;

  postMessageSafe({
    type: 'chunk',
    sessionId,
    headers,
    rows,
    delimiter,
    totalRowsRead,
    bytesProcessed: boundedCursor,
    progress,
    invalidRowCount,
    issues,
  });
}

function sanitizeHeaders(rawHeaders: string[]): string[] {
  const counts = new Map<string, number>();

  return rawHeaders.map((value, index) => {
    const normalized = `${value ?? ''}`.trim() || `column_${index + 1}`;
    const currentCount = counts.get(normalized) ?? 0;
    counts.set(normalized, currentCount + 1);

    return currentCount === 0 ? normalized : `${normalized}_${currentCount + 1}`;
  });
}

function normalizeRows(
  rawRows: string[][],
  headers: string[],
  nextRowNumber: number,
): { rows: CsvRow[]; issues: CsvParserIssue[] } {
  const rows: CsvRow[] = [];
  const issues: CsvParserIssue[] = [];

  rawRows.forEach((rawRow) => {
    if (!hasAnyCell(rawRow)) {
      return;
    }

    const values = rawRow.map((value) => `${value ?? ''}`);
    const rowNumber = nextRowNumber + rows.length;

    if (values.length !== headers.length) {
      issues.push({
        id: `shape-${rowNumber}-${values.length}`,
        level: 'warning',
        code: values.length > headers.length ? 'extra_columns' : 'missing_columns',
        row: rowNumber + 1,
        message:
          values.length > headers.length
            ? `La ligne ${rowNumber + 1} contient ${values.length} colonnes pour ${headers.length} headers. Les colonnes en trop sont tronquées.`
            : `La ligne ${rowNumber + 1} contient ${values.length} colonnes pour ${headers.length} headers. Les cellules manquantes sont complétées à vide.`,
      });
    }

    const normalized = values.slice(0, headers.length);
    while (normalized.length < headers.length) {
      normalized.push('');
    }

    rows.push({
      id: rowNumber,
      rowNumber,
      values: normalized,
    });
  });

  if (rows.length === 0 && rawRows.length > 0) {
    issues.push({
      id: `empty-chunk-${nextRowNumber}`,
      level: 'warning',
      code: 'empty_chunk',
      message: 'Un bloc du fichier ne contenait aucune ligne de données exploitable.',
    });
  }

  return { rows, issues };
}

function hasAnyCell(row: string[] | undefined): boolean {
  return (row ?? []).some((cell) => `${cell ?? ''}`.trim() !== '');
}

function normalizeIssues(errors: ParseError[]): CsvParserIssue[] {
  return errors.map((error, index) => ({
    id: `${error.code}-${error.row ?? 'unknown'}-${index}`,
    level: 'warning',
    code: error.code,
    row: typeof error.row === 'number' ? error.row + 1 : undefined,
    message: error.message,
  }));
}

function postMessageSafe(message: CsvWorkerEvent) {
  ctx.postMessage(message);
}

export {};
