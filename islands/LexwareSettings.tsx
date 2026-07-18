import { useEffect, useState } from "react";

interface Masked {
  key: string;
  secret: boolean;
  configured?: boolean;
  last4?: string | null;
  value?: string;
}

const api = (path: string, init?: RequestInit) => fetch(path, { credentials: "include", ...init });
const NS = "/admin/settings/lexware";

/**
 * Lexware settings — API key + URL + default net hourly rate + tax rate,
 * persisted in the core runtime settings store (`/admin/settings/lexware`,
 * admin-only). The secret key comes back masked (configured + last4); a blank
 * key on save keeps the existing value. The backend reads these DB-first with an
 * env fallback. A connection test hits GET /lexware/admin/test.
 */
export default function LexwareSettings() {
  const [loaded, setLoaded] = useState(false);
  const [keyState, setKeyState] = useState<Masked | null>(null);
  const [keyInput, setKeyInput] = useState("");
  const [url, setUrl] = useState("https://api.lexware.io/v1");
  const [rate, setRate] = useState("");
  const [tax, setTax] = useState("19");
  const [status, setStatus] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const res = await api(NS);
    if (!res.ok) {
      setStatus(res.status === 403 || res.status === 401 ? "Nur für Administratoren." : `Fehler (HTTP ${res.status}).`);
      setLoaded(true);
      return;
    }
    const d = await res.json();
    const map = new Map<string, Masked>((d.settings ?? []).map((s: Masked) => [s.key, s]));
    setKeyState(map.get("api_key") ?? null);
    setUrl(map.get("api_url")?.value || "https://api.lexware.io/v1");
    setRate(map.get("default_hourly_rate")?.value ?? "");
    setTax(map.get("default_tax_rate")?.value || "19");
    setLoaded(true);
  };

  useEffect(() => {
    void load();
  }, []);

  const save = async () => {
    setBusy(true);
    setStatus(null);
    const settings: Masked[] = [
      { key: "api_key", secret: true, value: keyInput.trim() },
      { key: "api_url", secret: false, value: url.trim() },
      { key: "default_hourly_rate", secret: false, value: rate.trim() },
      { key: "default_tax_rate", secret: false, value: tax.trim() },
    ];
    const res = await api(NS, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ settings }),
    });
    setBusy(false);
    if (res.ok) {
      setKeyInput("");
      setStatus("Gespeichert.");
      void load();
    } else {
      setStatus(`Fehler (HTTP ${res.status}).`);
    }
  };

  const test = async () => {
    setTestResult("Teste …");
    const res = await api("/lexware/admin/test");
    const d = await res.json().catch(() => ({}));
    if (res.ok && d.ok) {
      setTestResult("Verbindung erfolgreich.");
    } else {
      setTestResult(`Fehlgeschlagen: ${d.error ?? `HTTP ${res.status}`}`);
    }
  };

  const secretHint = keyState?.configured ? `konfiguriert (…${keyState.last4 ?? "????"})` : "nicht konfiguriert";

  if (!loaded) return <p>Wird geladen …</p>;

  return (
    <div className="lexware-settings space-y-4">
      <label className="block">
        <span className="text-sm">API-Key <em className="opacity-60">({secretHint})</em></span>
        <input
          type="password"
          value={keyInput}
          onChange={(e) => setKeyInput(e.target.value)}
          placeholder="Neuen Schlüssel setzen (leer = behalten)"
          autoComplete="off"
        />
      </label>

      <label className="block">
        <span className="text-sm">API-URL</span>
        <input type="text" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://api.lexware.io/v1" />
      </label>

      <div className="grid grid-cols-2 gap-3">
        <label className="block">
          <span className="text-sm">Standard-Stundensatz (netto)</span>
          <input type="number" min="0" step="0.01" value={rate} onChange={(e) => setRate(e.target.value)} placeholder="0" />
        </label>
        <label className="block">
          <span className="text-sm">Steuersatz (%)</span>
          <input type="number" min="0" step="0.1" value={tax} onChange={(e) => setTax(e.target.value)} placeholder="19" />
        </label>
      </div>

      {status ? <p className="status-pill status-pill--info">{status}</p> : null}
      <div className="flex items-center gap-3">
        <button type="button" onClick={save} disabled={busy}>Speichern</button>
        <button type="button" className="btn-secondary" onClick={test}>Verbindung testen</button>
      </div>
      {testResult ? <p className="text-sm opacity-80">{testResult}</p> : null}
    </div>
  );
}
