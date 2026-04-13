import { useRef } from 'react';

type CsvFilePickerProps = {
  disabled?: boolean;
  currentFileName?: string | null;
  onOpenRemoteBrowser: () => void;
  onLocalFileSelected: (file: File) => void;
};

export default function CsvFilePicker({
  disabled = false,
  currentFileName,
  onOpenRemoteBrowser,
  onLocalFileSelected,
}: CsvFilePickerProps) {
  const inputRef = useRef<HTMLInputElement | null>(null);

  const openLocalPicker = () => {
    if (disabled) {
      return;
    }

    inputRef.current?.click();
  };

  const handleFiles = (fileList: FileList | null) => {
    const file = fileList?.[0];
    if (!file) {
      return;
    }

    onLocalFileSelected(file);
  };

  return (
    <section className="csv-picker">
      <input
        ref={inputRef}
        type="file"
        accept=".csv,.tsv,text/csv,text/tab-separated-values,.txt"
        hidden
        onChange={(event) => handleFiles(event.target.files)}
      />

      <div className="csv-picker__dropzone">
        <div className="csv-picker__content">
          <div>
            <p className="csv-picker__eyebrow">CSV Explorer</p>
            <h1 className="csv-picker__title">Explorer un CSV massif du VPS ou de ton poste sans saturer l UI</h1>
            <p className="csv-picker__subtitle">
              Le mode principal ouvre maintenant un navigateur de fichiers cote VPS via Helix. Le fallback local reste
              disponible si besoin.
            </p>
          </div>

          <div className="csv-picker__actions">
            <div className="csv-picker__button-row">
              <button
                type="button"
                className="csv-button csv-button--primary"
                onClick={onOpenRemoteBrowser}
                disabled={disabled}
              >
                Ouvrir depuis le VPS
              </button>
              <button
                type="button"
                className="csv-button csv-button--ghost"
                onClick={openLocalPicker}
                disabled={disabled}
              >
                Fichier local
              </button>
            </div>
            <p className="csv-picker__hint">
              {currentFileName
                ? `Fichier courant : ${currentFileName}`
                : 'Selection prioritaire : fichiers internes du serveur Helix'}
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
