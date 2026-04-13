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
  source: 'local' | 'remote';
  path?: string;
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

export type CsvWorkerLocalSource = {
  kind: 'local';
  file: File;
};

export type CsvWorkerRemoteSource = {
  kind: 'remote';
  url: string;
  fileInfo: CsvFileInfo;
  requestHeaders?: Record<string, string>;
};

export interface CsvWorkerStartPayload {
  source: CsvWorkerLocalSource | CsvWorkerRemoteSource;
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

export interface CsvRemoteEntry {
  type: 'directory' | 'file';
  name: string;
  path: string;
  size: number | null;
  modified_at: string;
  extension: string | null;
}

export interface CsvRemoteListing {
  root: {
    label: string;
    path: string;
  };
  current_path: string;
  parent_path: string | null;
  entries: CsvRemoteEntry[];
}

export type CsvRemoteJobStatus = 'queued' | 'reading' | 'completed' | 'cancelled' | 'error';

export interface CsvRemoteJob {
  job_id: string;
  status: CsvRemoteJobStatus;
  snapshot: CsvExplorerSnapshot;
  file_path: string;
  cancel_requested: boolean;
  created_at: number;
  updated_at: number;
}
