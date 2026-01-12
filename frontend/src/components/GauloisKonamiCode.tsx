import { useEffect } from 'react';

const sequence = [
  'ArrowUp',
  'ArrowUp',
  'ArrowDown',
  'ArrowDown',
  'ArrowLeft',
  'ArrowRight',
  'ArrowLeft',
  'ArrowRight',
  'b',
  'a',
];

export default function GauloisKonamiCode() {
  useEffect(() => {
    let idx = 0;
    const handler = (event: KeyboardEvent) => {
      if (event.key === sequence[idx]) {
        idx += 1;
        if (idx === sequence.length) {
          idx = 0;
        }
        return;
      }
      idx = 0;
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  return null;
}

