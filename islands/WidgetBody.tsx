import { useEffect, useState } from "react";

interface Summary {
  configured: boolean;
  invoiceCount: number;
  recent: Array<{ id: number; customer_name: string | null; created_at: string }>;
}

/**
 * Lexware widget body — shows the total invoice count exported to Lexware and
 * whether the API is configured. Same-origin fetch with credentials (the deploy
 * wires the gateway).
 */
export default function WidgetBody() {
  const [data, setData] = useState<Summary | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    fetch("/lexware/summary", { credentials: "include" })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((d: Summary) => setData(d))
      .catch(() => setError(true));
  }, []);

  if (error) return <p className="widget__metric">—</p>;
  if (!data) return <p className="widget__metric">…</p>;

  return (
    <div className="widget__body">
      <p className="widget__metric">{data.invoiceCount}</p>
      <p className="widget__label">
        {data.configured ? "Rechnungen an Lexware" : "Lexware nicht konfiguriert"}
      </p>
    </div>
  );
}
