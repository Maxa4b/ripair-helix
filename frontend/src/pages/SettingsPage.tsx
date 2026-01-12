import type { FormEvent } from "react";
import { useEffect, useState } from "react";
import { useSettings, useUpdateSetting } from "../hooks/useSettings";
import { useRefreshOptionsCache } from "../hooks/useOptionsCache";

type InternalSmsSettings = {
  enabled: boolean;
  numbers: string[];
};

export default function SettingsPage() {
  const settingsQuery = useSettings();
  const { data, error, isPending } = settingsQuery;
  const updateSetting = useUpdateSetting();
  const isSavingSettings = updateSetting.isPending;
  const refreshCache = useRefreshOptionsCache();
  const [smsSettings, setSmsSettings] = useState<InternalSmsSettings>({
    enabled: false,
    numbers: [],
  });

  useEffect(() => {
    const payload = (data?.["notifications.internal_sms"] ?? {}) as Partial<InternalSmsSettings>;
    setSmsSettings({
      enabled: payload.enabled ?? false,
      numbers: payload.numbers ?? [],
    });
  }, [data]);

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const sanitizedNumbers = smsSettings.numbers
      .map((number) => number.trim())
      .filter((number) => number !== '');

    updateSetting.mutate({
      key: "notifications.internal_sms",
      value: {
        enabled: smsSettings.enabled,
        numbers: sanitizedNumbers,
      },
    });
  };

  const handleNumberChange = (index: number, value: string) => {
    setSmsSettings((prev) => {
      const next = [...prev.numbers];
      next[index] = value;
      return { ...prev, numbers: next };
    });
  };

  const handleAddNumber = () => {
    setSmsSettings((prev) => ({
      ...prev,
      numbers: [...prev.numbers, ""],
    }));
  };

  const handleRemoveNumber = (index: number) => {
    setSmsSettings((prev) => ({
      ...prev,
      numbers: prev.numbers.filter((_, i) => i !== index),
    }));
  };

  return (
    <div style={{ display: "grid", gap: 24, maxWidth: 640 }}>
      <header>
        <h2 style={{ margin: 0 }}>Parametres</h2>
        <p style={{ margin: 0, color: "#64748b" }}>
          Configure les notifications internes et autres options Helix.
        </p>
      </header>

      <section
        style={{
          background: "#fff",
          borderRadius: 14,
          padding: 24,
          boxShadow: "0 10px 28px rgba(15,23,42,0.08)",
          display: "grid",
          gap: 16,
        }}
      >
        <div>
          <h3 style={{ margin: 0 }}>Catalogue de devis</h3>
          <p style={{ margin: "6px 0 0", color: "#64748b", fontSize: 14 }}>
            Force les visiteurs du site public a recharger les options de devis (categories, marques, modeles, problemes) apres une modification en base.
          </p>
        </div>

        <button
          type="button"
          onClick={() => refreshCache.mutate()}
          style={refreshButtonStyle}
          disabled={refreshCache.isPending}
        >
          {refreshCache.isPending ? "Actualisation en cours..." : "Actualiser le cache des options"}
        </button>

        {refreshCache.data && (
          <span style={{ color: "#16a34a", fontSize: 13 }}>
            Options mises a jour (version {refreshCache.data.version ?? "?"}).
          </span>
        )}

        {refreshCache.error && (
          <span style={{ color: "#e53e3e", fontSize: 13 }}>
            Impossible d'actualiser le cache. Verifie la configuration Helix → RIPAIR_SITE_BASE_URL / RIPAIR_OPTIONS_CACHE_TOKEN.
          </span>
        )}
      </section>

      {isPending && <p>Chargement des parametres.</p>}
      {error && <p style={{ color: "#e53e3e" }}>Impossible de charger les parametres.</p>}

      <form
        onSubmit={handleSubmit}
        style={{
          background: "#fff",
          borderRadius: 14,
          padding: 24,
          boxShadow: "0 10px 28px rgba(15,23,42,0.08)",
          display: "grid",
          gap: 18,
        }}
      >
        <div>
          <h3 style={{ margin: 0 }}>Notifications SMS internes</h3>
          <p style={{ margin: "6px 0 0", color: "#64748b", fontSize: 14 }}>
            Avertir l'equipe par SMS lorsqu'un rendez-vous est cree ou modifie.
          </p>
        </div>

        <label style={checkboxLabel}>
          <input
            type="checkbox"
            checked={smsSettings.enabled}
            onChange={(event) =>
              setSmsSettings((prev) => ({
                ...prev,
                enabled: event.target.checked,
              }))
            }
          />
          Activer l'envoi de SMS internes
        </label>

        <label style={{ display: "grid", gap: 6, fontSize: 14, color: "#475569" }}>
          Numeros destinataires
          <div style={{ display: "grid", gap: 8 }}>
            {smsSettings.numbers.length === 0 && (
              <span style={{ fontSize: 13, color: "#94a3b8" }}>
                Aucun numero enregistré. Ajoute un premier numero pour recevoir les notifications internes.
              </span>
            )}
            {smsSettings.numbers.map((value, index) => (
              <div key={index} style={{ display: "flex", gap: 8, alignItems: "center" }}>
                <input
                  type="text"
                  value={value}
                  onChange={(event) => handleNumberChange(index, event.target.value)}
                  placeholder="+33601020304"
                  style={{ ...inputStyle, flex: 1 }}
                />
                <button
                  type="button"
                  onClick={() => handleRemoveNumber(index)}
                  style={removeButtonStyle}
                  aria-label={`Supprimer le numero ${value || index + 1}`}
                >
                  Supprimer
                </button>
              </div>
            ))}
            <button type="button" onClick={handleAddNumber} style={addButtonStyle}>
              + Ajouter un numero
            </button>
          </div>
        </label>

        <button type="submit" style={buttonStyle} disabled={isSavingSettings}>
          {isSavingSettings ? "Enregistrement..." : "Enregistrer les parametres"}
        </button>

        {updateSetting.error && (
          <span style={{ color: "#e53e3e" }}>Erreur lors de la mise a jour des parametres.</span>
        )}
      </form>
    </div>
  );
}

const checkboxLabel: React.CSSProperties = {
  display: "flex",
  alignItems: "center",
  gap: 10,
  fontSize: 15,
  color: "#1f2937",
};

const inputStyle: React.CSSProperties = {
  borderRadius: 8,
  border: "1px solid #cbd5f5",
  padding: "10px 12px",
  background: "#fff",
  fontSize: 14,
};

const buttonStyle: React.CSSProperties = {
  borderRadius: 8,
  border: "none",
  padding: "10px 16px",
  background: "#2563eb",
  color: "#fff",
  fontWeight: 600,
  cursor: "pointer",
};

const addButtonStyle: React.CSSProperties = {
  alignSelf: "flex-start",
  borderRadius: 6,
  border: "1px dashed #2563eb",
  background: "transparent",
  color: "#2563eb",
  fontWeight: 600,
  fontSize: 13,
  padding: "6px 10px",
  cursor: "pointer",
};

const removeButtonStyle: React.CSSProperties = {
  borderRadius: 6,
  border: "1px solid #ef4444",
  background: "transparent",
  color: "#ef4444",
  fontWeight: 600,
  fontSize: 12,
  padding: "6px 10px",
  cursor: "pointer",
};

const refreshButtonStyle: React.CSSProperties = {
  borderRadius: 8,
  border: "1px solid #2563eb",
  background: "#2563eb",
  color: "#fff",
  fontWeight: 600,
  padding: "10px 16px",
  cursor: "pointer",
  alignSelf: "flex-start",
};
