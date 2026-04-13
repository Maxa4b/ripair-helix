export type CsvExplorerStatus =
  | 'idle'
  | 'analyzing'
  | 'reading'
  | 'paused'
  | 'ready'
  | 'cancelled'
  | 'error';

export type CsvEncoding = 'utf-8' | 'iso-8859-1' | 'windows-1252';

export type CsvDelimiterOption = 'auto' | ',' | ';' | '\t' | '|';

export type CsvBufferScope = 'preview' | 'recent';

export type CsvSortDirection = 'asc' | 'desc';

export type CsvIssueLevel = 'warning' | 'error';

export interface CsvRow {
  id: number;
  rowNumber: number;
  values: string[];
}

export interface CsvFileInfo {
  name: string;
  size: number;
  type: string;
  lastModified: number;
}

export interface CsvParserIssue {
  id: string;
  level: CsvIssueLevel;
  code: string;
  message: string;
  row?: number;
}

export interface CsvExplorerConfig {
  delimiter: CsvDelimiterOption;
  encoding: CsvEncoding;
  chunkSize: number;
  previewRowLimit: number;
  recentRowLimit: number;
  issueLimit: number;
}

export interface CsvExplorerSnapshot {
  file: CsvFileInfo | null;
  status: CsvExplorerStatus;
  headers: string[];
  previewRows: CsvRow[];
  recentRows: CsvRow[];
  delimiter: string | null;
  totalRowsRead: number;
  bytesProcessed: number;
  progress: number;
  invalidRowCount: number;
  issues: CsvParserIssue[];
  warning: string | null;
  error: string | null;
  startedAt: number | null;
  completedAt: number | null;
}

export interface CsvWorkerStartPayload {
  file: File;
  config: CsvExplorerConfig;
}

export type CsvWorkerCommand =
  | {
      type: 'start';
      sessionId: number;
      payload: CsvWorkerStartPayload;
    }
  | {
      type: 'pause';
      sessionId: number;
    }
  | {
      type: 'resume';
      sessionId: number;
    }
  | {
      type: 'abort';
      sessionId: number;
    };

export type CsvWorkerEvent =
  | {
      type: 'started';
      sessionId: number;
      file: CsvFileInfo;
    }
  | {
      type: 'chunk';
      sessionId: number;
      headers?: string[];
      rows: CsvRow[];
      delimiter: string | null;
      totalRowsRead: number;
      bytesProcessed: number;
      progress: number;
      invalidRowCount: number;
      issues: CsvParserIssue[];
    }
  | {
      type: 'paused';
      sessionId: number;
    }
  | {
      type: 'resumed';
      sessionId: number;
    }
  | {
      type: 'completed';
      sessionId: number;
      delimiter: string | null;
      totalRowsRead: number;
      bytesProcessed: number;
      invalidRowCount: number;
      issues: CsvParserIssue[];
    }
  | {
      type: 'aborted';
      sessionId: number;
    }
  | {
      type: 'error';
      sessionId: number;
      message: string;
      issues: CsvParserIssue[];
    };

export interface CsvSortState {
  column: string | null;
  direction: CsvSortDirection;
}
