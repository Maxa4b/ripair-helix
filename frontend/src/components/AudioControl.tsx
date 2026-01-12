import { useCallback } from 'react';
import type { ChangeEvent, KeyboardEvent } from 'react';

export type AudioControlProps = {
  volume: number;
  muted: boolean;
  canIncrease?: boolean;
  canDecrease?: boolean;
  onVolumeChange: (value: number) => void;
  onToggleMute: () => void;
  reducedMotion?: boolean;
  className?: string;
};

export default function AudioControl({
  volume,
  muted,
  canIncrease = true,
  canDecrease = true,
  onVolumeChange,
  onToggleMute,
  className,
}: AudioControlProps) {
  const handleChange = useCallback(
    (event: ChangeEvent<HTMLInputElement>) => {
      const next = Number.parseInt(event.target.value, 10);
      if ((next > volume && !canIncrease) || (next < volume && !canDecrease)) {
        return;
      }
      onVolumeChange(next);
    },
    [canDecrease, canIncrease, onVolumeChange, volume],
  );

  const handleKeyDown = useCallback(
    (event: KeyboardEvent<HTMLDivElement>) => {
      if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (canIncrease) {
          onVolumeChange(volume + 10);
        }
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (canDecrease) {
          onVolumeChange(volume - 10);
        }
      }
      if (event.key.toLowerCase() === 'm' || event.key === ' ') {
        event.preventDefault();
        onToggleMute();
      }
    },
    [canDecrease, canIncrease, onToggleMute, onVolumeChange, volume],
  );

  return (
    <div
      className={className}
      role="group"
      aria-label="Contrôles audio"
      tabIndex={0}
      onKeyDown={handleKeyDown}
      data-muted={muted}
    >
      <button
        type="button"
        aria-label={muted ? 'Activer le son' : 'Couper le son'}
        onClick={onToggleMute}
        data-testid="audio-control-mute"
        aria-pressed={muted}
      >
        {muted ? '🔇' : '🔊'}
      </button>
      <label>
        <span className="sr-only">Volume</span>
        <input
          type="range"
          min={0}
          max={100}
          step={1}
          value={volume}
          onChange={handleChange}
          aria-valuenow={volume}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuetext={muted ? 'Muet' : `${volume} %`}
        />
      </label>
    </div>
  );
}
