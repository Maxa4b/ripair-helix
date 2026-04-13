import type {
  CsvBufferScope,
  CsvExplorerConfig,
  CsvExplorerSnapshot,
  CsvParserIssue,
  CsvRow,
  CsvSortState,
} from './types';

export const DEFAULT_CSV_EXPLORER_CONFIG: CsvExplorerConfig = {
  delimiter: 'auto',
  encoding: 'utf-8',
  chunkSize: 1024 * 1024 * 2,
  previewRowLimit: 1500,
  recentRowLimit: 18000,
  issueLimit: 12,
};

export const VIRTUAL_ROW_HEIGHT = 40;
export const VIRTUAL_OVERSCAN = 14;

export const createInitialCsvSnapshot = (): CsvExplorerSnapshot => ({
  file: null,
  status: 'idle',
  headers: [],
  previewRows: [],
  recentRows: [],
  delimiter: null,
  totalRowsRead: 0,
  bytesProcessed: 0,
  progress: 0,
  invalidRowCount: 0,
  issues: [],
  warning: null,
  error: null,
  startedAt: null,
  completedAt: null,
});

export function appendIssues(
  currentIssues: CsvParserIssue[],
  nextIssues: CsvParserIssue[],
  limit: number,
): CsvParserIssue[] {
  if (nextIssues.length === 0) {
    return currentIssues;
  }

  const merged = [...currentIssues];
  const knownIds = new Set(merged.map((issue) => issue.id));

  nextIssues.forEach((issue) => {
    if (knownIds.has(issue.id)) {
      return;
    }

    knownIds.add(issue.id);
    merged.push(issue);
  });

  return merged.slice(-limit);
}

export function mergePreviewRows(currentRows: CsvRow[], nextRows: CsvRow[], limit: number): CsvRow[] {
  if (currentRows.length >= limit || nextRows.length === 0) {
    return currentRows;
  }

  return [...currentRows, ...nextRows.slice(0, limit - currentRows.length)];
}

export function mergeRecentRows(currentRows: CsvRow[], nextRows: CsvRow[], limit: number): CsvRow[] {
  if (nextRows.length === 0) {
    return currentRows;
  }

  return [...currentRows, ...nextRows].slice(-limit);
}

export function formatBytes(value: number): string {
  if (!Number.isFinite(value) || value <= 0) {
    return '0 o';
  }

  const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
  const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
  const scaled = value / 1024 ** index;

  return `${scaled.toFixed(scaled >= 100 || index === 0 ? 0 : scaled >= 10 ? 1 : 2)} ${units[index]}`;
}

export function formatInteger(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(value);
}

export function formatProgress(value: number): string {
  return `${Math.round(value * 100)}%`;
}

export function buildBufferScopeLabel(scope: CsvBufferScope): string {
  return scope === 'preview' ? 'Apercu initial' : 'Tampon recent';
}

export function buildBufferScopeDescription(scope: CsvBufferScope, snapshot: CsvExplorerSnapshot): string {
  if (scope === 'preview') {
    return `${formatInteger(snapshot.previewRows.length)} premieres lignes conservees pour l apercu rapide.`;
  }

  return `${formatInteger(snapshot.recentRows.length)} lignes les plus recentes conservees pendant la lecture.`;
}

export function countRetainedRows(snapshot: CsvExplorerSnapshot): number {
  const ids = new Set<number>();
  snapshot.previewRows.forEach((row) => ids.add(row.id));
  snapshot.recentRows.forEach((row) => ids.add(row.id));
  return ids.size;
}

export function detectFrontLimitWarning(snapshot: CsvExplorerSnapshot, config: CsvExplorerConfig): string | null {
  if (!snapshot.file) {
    return null;
  }

  const retainedRows = countRetainedRows(snapshot);

  if (snapshot.totalRowsRead > config.previewRowLimit + config.recentRowLimit) {
    return `Le navigateur reste en mode preview intelligent. Les filtres, le tri et l export ne portent que sur ${formatInteger(retainedRows)} lignes retenues en memoire, pas sur l ensemble du fichier.`;
  }

  if (snapshot.file.size >= 1024 ** 3) {
    return 'Fichier tres volumineux detecte. Les operations completes sur tout le dataset ne sont pas realistes cote navigateur sans moteur backend ou DuckDB.';
  }

  return null;
}

export function applyRowFilters(
  rows: CsvRow[],
  headers: string[],
  globalSearch: string,
  columnName: string,
  columnValue: string,
  sort: CsvSortState | null,
): CsvRow[] {
  const normalizedSearch = globalSearch.trim().toLocaleLowerCase('fr');
  const normalizedColumnValue = columnValue.trim().toLocaleLowerCase('fr');
  const columnIndex = columnName ? headers.indexOf(columnName) : -1;

  const filtered = rows.filter((row) => {
    if (normalizedSearch) {
      const matchesSearch = row.values.some((value) =>
        value.toLocaleLowerCase('fr').includes(normalizedSearch),
      );

      if (!matchesSearch) {
        return false;
      }
    }

    if (columnIndex >= 0 && normalizedColumnValue) {
      const value = row.values[columnIndex] ?? '';
      if (!value.toLocaleLowerCase('fr').includes(normalizedColumnValue)) {
        return false;
      }
    }

    return true;
  });

  if (!sort?.column) {
    return filtered;
  }

  const sortIndex = headers.indexOf(sort.column);
  if (sortIndex < 0) {
    return filtered;
  }

  return [...filtered].sort((left, right) => {
    const leftValue = left.values[sortIndex] ?? '';
    const rightValue = right.values[sortIndex] ?? '';
    const numericLeft = Number(leftValue);
    const numericRight = Number(rightValue);

    let comparison = 0;

    if (Number.isFinite(numericLeft) && Number.isFinite(numericRight) && leftValue !== '' && rightValue !== '') {
      comparison = numericLeft - numericRight;
    } else {
      comparison = leftValue.localeCompare(rightValue, 'fr', {
        sensitivity: 'base',
        numeric: true,
      });
    }

    if (comparison !== 0) {
      return sort.direction === 'asc' ? comparison : -comparison;
    }

    return left.rowNumber - right.rowNumber;
  });
}
