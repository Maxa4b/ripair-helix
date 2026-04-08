import type { ProspectingStatus } from '../../hooks/useProspecting';

export const statusDefinitions: Array<{
  key: ProspectingStatus;
  label: string;
  color: string;
  textColor: string;
}> = [
  {
    key: 'non_contacte',
    label: 'Non contacte',
    color: '#fee2e2',
    textColor: '#b91c1c',
  },
  {
    key: 'en_cours_de_contact',
    label: 'En cours',
    color: '#dbeafe',
    textColor: '#1d4ed8',
  },
  {
    key: 'contacte',
    label: 'Contacte',
    color: '#dcfce7',
    textColor: '#15803d',
  },
];

export function statusLabel(status: ProspectingStatus) {
  return statusDefinitions.find((item) => item.key === status)?.label ?? status;
}
