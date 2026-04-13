import { useRef, useState } from 'react';

type CsvFilePickerProps = {
  disabled?: boolean;
  currentFileName?: string | null;
  onFileSelected: (file: File) => void;
};

export default function CsvFilePicker({
  disabled = false,
  currentFileName,
  onFileSelected,
}: CsvFilePickerProps) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [isDragging, setIsDragging] = useState(false);

  const openPicker = () => {
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

    onFileSelected(file);
  };

  return (
    <section className={`csv-picker${isDragging ? ' csv-picker--dragging' : ''}`}>
      <input
        ref={inputRef}
        type="file"
        accept=".csv,.tsv,text/csv,text/tab-separated-values,.txt"
        hidden
        onChange={(event) => handleFiles(event.target.files)}
      />

      <div
        className="csv-picker__dropzone"
        onDragEnter={(event) => {
          event.preventDefault();
          if (!disabled) {
            setIsDragging(true);
          }
        }}
        onDragLeave={(event) => {
          event.preventDefault();
          if (event.currentTarget.contains(event.relatedTarget as Node | null)) {
            return;
          }
          setIsDragging(false);
        }}
        onDragOver={(event) => {
          event.preventDefault();
        }}
        onDrop={(event) => {
          event.preventDefault();
          setIsDragging(false);
          if (disabled) {
            return;
          }
          handleFiles(event.dataTransfer.files);
        }}
      >
        <div className="csv-picker__content">
          <div>
            <p className="csv-picker__eyebrow">CSV Explorer</p>
            <h1 className="csv-picker__title">Explorer un CSV local massif sans charger tout le fichier</h1>
            <p className="csv-picker__subtitle">
              Selectionne ou depose un fichier local. Le parsing se fait par chunks dans un worker, avec affichage
              virtualise et buffer memoire borne.
            </p>
          </div>

          <div className="csv-picker__actions">
            <button type="button" className="csv-button csv-button--primary" onClick={openPicker} disabled={disabled}>
              Ouvrir un CSV
            </button>
            <p className="csv-picker__hint">
              {currentFileName
                ? `Fichier courant : ${currentFileName}`
                : 'Formats attendus : .csv, .tsv ou texte delimite'}
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
