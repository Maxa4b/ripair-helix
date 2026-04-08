export function formatDateTime(value?: string | null) {
  if (!value) {
    return '-';
  }

  return new Date(value).toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export async function copyText(value?: string | null) {
  if (!value) {
    return false;
  }

  if (!navigator.clipboard?.writeText) {
    return false;
  }

  await navigator.clipboard.writeText(value);
  return true;
}
