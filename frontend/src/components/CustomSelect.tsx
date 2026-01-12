import { useEffect, useRef, useState } from "react";

interface CustomSelectProps {
  options: string[];
  value?: string;
  onChange?: (value: string) => void;
  placeholder?: string;
}

export default function CustomSelect({
  options,
  value,
  onChange,
  placeholder = "— Aucune —",
}: CustomSelectProps) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);

  const formatLabel = (opt: string) => opt.replace(/\.json$/i, "");

  const handleSelect = (option: string) => {
    onChange?.(option);
    setOpen(false);
  };

  // 🔒 Fermer si clic en dehors + touche Échap
  useEffect(() => {
    const onPointerDown = (e: PointerEvent) => {
      if (!rootRef.current) return;
      if (!rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("pointerdown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("pointerdown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, []);

  return (
    <div className="custom-select" ref={rootRef}>
      <button
        type="button"
        className={`select-trigger ${value ? "selected" : ""}`}
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <div className="select-dot" />
        <span className="select-text">{value ? formatLabel(value) : placeholder}</span>
        <span className="arrow">▾</span>
      </button>

      {open && (
        <ul className="select-menu" role="listbox">
          {options.map((opt) => {
            const active = opt === value;
            return (
              <li
                key={opt}
                role="option"
                aria-selected={active}
                className={`select-option ${active ? "active" : ""}`}
                onMouseDown={(e) => {
                    e.preventDefault();   // évite le focus/change qui peut rouvrir
                    e.stopPropagation();  // ne remonte pas au trigger
                    handleSelect(opt);    // sélection + setOpen(false)
                }}
                tabIndex={0}
                onKeyDown={(e) => {
                    if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
                    handleSelect(opt);
                    }
                }}
                >
                <div className="select-dot" />
                <span>{formatLabel(opt)}</span>
                </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
