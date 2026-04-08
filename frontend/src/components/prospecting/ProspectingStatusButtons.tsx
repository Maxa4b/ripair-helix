import type { ProspectingStatus } from '../../hooks/useProspecting';
import { statusDefinitions } from './prospectingStatusMeta';

type Props = {
  status: ProspectingStatus;
  disabled?: boolean;
  onChange: (status: ProspectingStatus) => void;
};

export default function ProspectingStatusButtons({ status, disabled, onChange }: Props) {
  return (
    <div className="prospecting-status-row">
      {statusDefinitions.map((definition) => (
        <button
          key={definition.key}
          type="button"
          className={`prospecting-status-button${status === definition.key ? ' prospecting-status-button--active' : ''}`}
          onClick={() => onChange(definition.key)}
          disabled={disabled}
          style={{
            background: definition.color,
            color: definition.textColor,
            opacity: status === definition.key ? 1 : 0.78,
          }}
        >
          {definition.label}
        </button>
      ))}
    </div>
  );
}
