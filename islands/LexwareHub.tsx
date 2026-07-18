import { useEffect, useState } from "react";

const api = (path: string, init?: RequestInit) => fetch(path, { credentials: "include", ...init });

interface Customer {
  id: number;
  name: string;
  email: string | null;
  lexware_contact_id: string | null;
  default_hourly_rate: number | null;
  tax_rate_percent: number | null;
  note: string | null;
  project_count?: number | null;
  projects?: Project[];
}
interface Project {
  id: number;
  customer_id: number;
  title: string;
  hourly_rate: number | null;
  status: string;
}
interface Entry {
  id: number;
  started_at: string;
  ended_at: string;
  note: string | null;
  duration_minutes: number;
}
interface Lead {
  source_type: "contact_message" | "ticket";
  source_id: number;
  name: string;
  email: string;
  company: string | null;
  lexware_contact_id: string | null;
}
interface Invoice {
  id: number;
  lexware_invoice_id: string;
  customer_name: string | null;
  period_from: string | null;
  period_to: string | null;
  total_minutes: number;
  line_item_count: number;
  finalized: boolean;
  created_at: string;
}

type Tab = "customers" | "time" | "contacts" | "invoices";

const fmtHours = (min: number) => (min / 60).toFixed(2).replace(".", ",");

export default function LexwareHub() {
  const [tab, setTab] = useState<Tab>("customers");
  const tabs: Array<[Tab, string]> = [
    ["customers", "Kunden"],
    ["time", "Zeit zuordnen"],
    ["contacts", "Kontakte"],
    ["invoices", "Rechnungen"],
  ];
  return (
    <div className="lexware-hub">
      <nav className="lexware-tabs" role="tablist">
        {tabs.map(([id, label]) => (
          <button
            key={id}
            type="button"
            role="tab"
            aria-selected={tab === id}
            className={tab === id ? "tab tab--active" : "tab"}
            onClick={() => setTab(id)}
          >
            {label}
          </button>
        ))}
      </nav>
      <div className="lexware-tabpanel">
        {tab === "customers" ? <CustomersTab /> : null}
        {tab === "time" ? <TimeTab /> : null}
        {tab === "contacts" ? <ContactsTab /> : null}
        {tab === "invoices" ? <InvoicesTab /> : null}
      </div>
    </div>
  );
}

/** Customer + project directory. */
function CustomersTab() {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [selected, setSelected] = useState<Customer | null>(null);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [rate, setRate] = useState("");
  const [status, setStatus] = useState<string | null>(null);

  const load = async () => {
    const res = await api("/lexware/customers");
    if (res.ok) setCustomers((await res.json()).customers ?? []);
  };
  useEffect(() => {
    void load();
  }, []);

  const open = async (id: number) => {
    const res = await api(`/lexware/customers/${id}`);
    if (res.ok) setSelected(await res.json());
  };

  const addCustomer = async () => {
    if (name.trim() === "") return;
    const res = await api("/lexware/customers", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name, email, default_hourly_rate: rate }),
    });
    if (res.ok) {
      setName("");
      setEmail("");
      setRate("");
      void load();
    } else {
      setStatus(`Fehler (HTTP ${res.status}).`);
    }
  };

  const pushContact = async (id: number) => {
    setStatus("Sende an Lexware …");
    const res = await api(`/lexware/customers/${id}/push-contact`, { method: "POST" });
    const d = await res.json().catch(() => ({}));
    setStatus(res.ok ? "Kontakt in Lexware angelegt." : `Fehler: ${d.error ?? res.status}`);
    void open(id);
    void load();
  };

  return (
    <div className="lexware-customers grid gap-4 md:grid-cols-2">
      <div>
        <h4>Kunden</h4>
        <ul className="lx-list">
          {customers.map((c) => (
            <li key={c.id}>
              <button type="button" className="lx-list__item" onClick={() => void open(c.id)}>
                <strong>{c.name}</strong>
                <span className="opacity-70"> · {c.project_count ?? 0} Projekte</span>
                {c.lexware_contact_id ? <span className="chip chip--ok"> Lexware</span> : null}
              </button>
            </li>
          ))}
          {customers.length === 0 ? <li className="opacity-70">Noch keine Kunden.</li> : null}
        </ul>

        <div className="lx-form">
          <h5>Neuer Kunde</h5>
          <input type="text" placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} />
          <input type="email" placeholder="E-Mail (optional)" value={email} onChange={(e) => setEmail(e.target.value)} />
          <input type="number" min="0" step="0.01" placeholder="Stundensatz netto (optional)" value={rate} onChange={(e) => setRate(e.target.value)} />
          <button type="button" onClick={addCustomer}>Anlegen</button>
        </div>
        {status ? <p className="status-pill status-pill--info">{status}</p> : null}
      </div>

      <div>
        {selected ? <CustomerDetail customer={selected} onChanged={() => void open(selected.id)} onPush={() => void pushContact(selected.id)} /> : <p className="opacity-70">Kunde wählen …</p>}
      </div>
    </div>
  );
}

function CustomerDetail({ customer, onChanged, onPush }: { customer: Customer; onChanged: () => void; onPush: () => void }) {
  const [title, setTitle] = useState("");
  const [rate, setRate] = useState("");

  const addProject = async () => {
    if (title.trim() === "") return;
    const res = await api(`/lexware/customers/${customer.id}/projects`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ title, hourly_rate: rate }),
    });
    if (res.ok) {
      setTitle("");
      setRate("");
      onChanged();
    }
  };

  return (
    <div className="lx-detail">
      <h4>{customer.name}</h4>
      <p className="opacity-80">
        {customer.email ?? "keine E-Mail"} ·{" "}
        {customer.lexware_contact_id ? `Lexware-Kontakt ${customer.lexware_contact_id}` : "nicht in Lexware"}
      </p>
      <button type="button" onClick={onPush} disabled={customer.lexware_contact_id !== null}>
        {customer.lexware_contact_id ? "In Lexware angelegt" : "Als Lexware-Kontakt anlegen"}
      </button>

      <h5>Projekte</h5>
      <ul className="lx-list">
        {(customer.projects ?? []).map((p) => (
          <li key={p.id}>
            {p.title}
            {p.hourly_rate !== null ? <span className="opacity-70"> · {p.hourly_rate} €/h</span> : null}
            {p.status === "archived" ? <span className="chip"> archiviert</span> : null}
          </li>
        ))}
        {(customer.projects ?? []).length === 0 ? <li className="opacity-70">Noch keine Projekte.</li> : null}
      </ul>
      <div className="lx-form">
        <input type="text" placeholder="Projekttitel" value={title} onChange={(e) => setTitle(e.target.value)} />
        <input type="number" min="0" step="0.01" placeholder="Stundensatz (optional, überschreibt Kunde)" value={rate} onChange={(e) => setRate(e.target.value)} />
        <button type="button" onClick={addProject}>Projekt anlegen</button>
      </div>
    </div>
  );
}

/** Reusable customer→project picker used by the time + invoice tabs. */
function ProjectPicker({ projectId, onChange }: { projectId: number | null; onChange: (id: number | null) => void }) {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [customerId, setCustomerId] = useState<number | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);

  useEffect(() => {
    void api("/lexware/customers").then(async (r) => {
      if (r.ok) setCustomers((await r.json()).customers ?? []);
    });
  }, []);

  useEffect(() => {
    if (customerId === null) {
      setProjects([]);
      return;
    }
    void api(`/lexware/customers/${customerId}`).then(async (r) => {
      if (r.ok) setProjects((await r.json()).projects ?? []);
    });
  }, [customerId]);

  return (
    <div className="lx-picker flex gap-3">
      <select
        value={customerId ?? ""}
        onChange={(e) => {
          const v = e.target.value === "" ? null : Number(e.target.value);
          setCustomerId(v);
          onChange(null);
        }}
      >
        <option value="">Kunde …</option>
        {customers.map((c) => (
          <option key={c.id} value={c.id}>
            {c.name}
          </option>
        ))}
      </select>
      <select value={projectId ?? ""} onChange={(e) => onChange(e.target.value === "" ? null : Number(e.target.value))} disabled={customerId === null}>
        <option value="">Projekt …</option>
        {projects.map((p) => (
          <option key={p.id} value={p.id}>
            {p.title}
          </option>
        ))}
      </select>
    </div>
  );
}

/** Assign tracked time entries to a Lexware project. */
function TimeTab() {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [entries, setEntries] = useState<Entry[]>([]);
  const [projectId, setProjectId] = useState<number | null>(null);
  const [status, setStatus] = useState<string | null>(null);

  const load = async () => {
    const q = new URLSearchParams();
    if (from) q.set("from", from);
    if (to) q.set("to", to);
    const res = await api(`/lexware/time/unassigned?${q.toString()}`);
    if (res.ok) setEntries((await res.json()).entries ?? []);
  };
  useEffect(() => {
    void load();
  }, []);

  const assign = async (entryId: number) => {
    if (projectId === null) {
      setStatus("Bitte zuerst ein Projekt wählen.");
      return;
    }
    const res = await api("/lexware/time/assign", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ timeEntryId: entryId, projectId }),
    });
    if (res.ok) {
      setEntries((prev) => prev.filter((e) => e.id !== entryId));
      setStatus("Zugeordnet.");
    } else {
      setStatus(`Fehler (HTTP ${res.status}).`);
    }
  };

  return (
    <div className="lexware-time">
      <p className="opacity-80">Nicht zugeordnete, abgeschlossene Zeiteinträge einem Lexware-Projekt zuweisen.</p>
      <div className="lx-form flex flex-wrap gap-3 items-end">
        <label>
          <span className="text-sm">Von</span>
          <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label>
          <span className="text-sm">Bis</span>
          <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </label>
        <button type="button" onClick={() => void load()}>Filtern</button>
        <ProjectPicker projectId={projectId} onChange={setProjectId} />
      </div>
      {status ? <p className="status-pill status-pill--info">{status}</p> : null}
      <table className="lx-table">
        <thead>
          <tr>
            <th>Datum</th>
            <th>Notiz</th>
            <th>Dauer</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {entries.map((e) => (
            <tr key={e.id}>
              <td>{e.started_at.slice(0, 10)}</td>
              <td>{e.note ?? "—"}</td>
              <td>{fmtHours(e.duration_minutes)} h</td>
              <td>
                <button type="button" onClick={() => void assign(e.id)}>Zuordnen</button>
              </td>
            </tr>
          ))}
          {entries.length === 0 ? (
            <tr>
              <td colSpan={4} className="opacity-70">Keine offenen Einträge.</td>
            </tr>
          ) : null}
        </tbody>
      </table>
    </div>
  );
}

/** Push leads (from the ticket systems) to Lexware as contacts. */
function ContactsTab() {
  const [leads, setLeads] = useState<Lead[]>([]);
  const [status, setStatus] = useState<string | null>(null);

  const load = async () => {
    const res = await api("/lexware/leads");
    if (res.ok) setLeads((await res.json()).leads ?? []);
  };
  useEffect(() => {
    void load();
  }, []);

  const push = async (lead: Lead) => {
    setStatus("Sende an Lexware …");
    const res = await api("/lexware/leads/push", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        source_type: lead.source_type,
        source_id: lead.source_id,
        name: lead.name,
        email: lead.email,
        company: lead.company,
      }),
    });
    const d = await res.json().catch(() => ({}));
    setStatus(res.ok ? "Kontakt angelegt." : `Fehler: ${d.error ?? res.status}`);
    void load();
  };

  return (
    <div className="lexware-contacts">
      <p className="opacity-80">Kontakte aus Kontaktanfragen &amp; Tickets als Lexware-Kontakte anlegen.</p>
      {status ? <p className="status-pill status-pill--info">{status}</p> : null}
      <table className="lx-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>E-Mail</th>
            <th>Firma</th>
            <th>Quelle</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {leads.map((l) => (
            <tr key={`${l.source_type}-${l.source_id}`}>
              <td>{l.name || "—"}</td>
              <td>{l.email}</td>
              <td>{l.company ?? "—"}</td>
              <td>{l.source_type === "ticket" ? "Ticket" : "Kontaktformular"}</td>
              <td>
                {l.lexware_contact_id ? (
                  <span className="chip chip--ok">in Lexware</span>
                ) : (
                  <button type="button" onClick={() => void push(l)}>Anlegen</button>
                )}
              </td>
            </tr>
          ))}
          {leads.length === 0 ? (
            <tr>
              <td colSpan={5} className="opacity-70">Keine Kontakt-Kandidaten gefunden.</td>
            </tr>
          ) : null}
        </tbody>
      </table>
    </div>
  );
}

/** Export billable time as a Lexware invoice + list past exports. */
function InvoicesTab() {
  const [projectId, setProjectId] = useState<number | null>(null);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [finalize, setFinalize] = useState(false);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [invoices, setInvoices] = useState<Invoice[]>([]);

  const load = async () => {
    const res = await api("/lexware/invoices");
    if (res.ok) setInvoices((await res.json()).invoices ?? []);
  };
  useEffect(() => {
    void load();
  }, []);

  const exportInvoice = async () => {
    if (projectId === null) {
      setStatus("Bitte ein Projekt wählen.");
      return;
    }
    setBusy(true);
    setStatus(null);
    const res = await api("/lexware/invoices/from-project", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ projectId, from, to, finalize }),
    });
    const d = await res.json().catch(() => ({}));
    setBusy(false);
    if (res.ok) {
      setStatus(`Rechnung erstellt (${fmtHours(d.totalMinutes ?? 0)} h, ${finalize ? "final" : "Entwurf"}).`);
      void load();
    } else {
      setStatus(`Fehler: ${d.error ?? `HTTP ${res.status}`}`);
    }
  };

  return (
    <div className="lexware-invoices">
      <div className="lx-form flex flex-wrap gap-3 items-end">
        <ProjectPicker projectId={projectId} onChange={setProjectId} />
        <label>
          <span className="text-sm">Von</span>
          <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label>
          <span className="text-sm">Bis</span>
          <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </label>
        <label className="flex items-center gap-2">
          <input type="checkbox" checked={finalize} onChange={(e) => setFinalize(e.target.checked)} />
          <span className="text-sm">Finalisieren (echte Rechnung)</span>
        </label>
        <button type="button" onClick={exportInvoice} disabled={busy}>Rechnung erstellen</button>
      </div>
      {status ? <p className="status-pill status-pill--info">{status}</p> : null}

      <h5>Bisherige Exporte</h5>
      <table className="lx-table">
        <thead>
          <tr>
            <th>Datum</th>
            <th>Kunde</th>
            <th>Zeitraum</th>
            <th>Stunden</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {invoices.map((i) => (
            <tr key={i.id}>
              <td>{i.created_at.slice(0, 10)}</td>
              <td>{i.customer_name ?? "—"}</td>
              <td>{i.period_from && i.period_to ? `${i.period_from} – ${i.period_to}` : "—"}</td>
              <td>{fmtHours(i.total_minutes)} h</td>
              <td>{i.finalized ? "Final" : "Entwurf"}</td>
            </tr>
          ))}
          {invoices.length === 0 ? (
            <tr>
              <td colSpan={5} className="opacity-70">Noch keine Rechnungen exportiert.</td>
            </tr>
          ) : null}
        </tbody>
      </table>
    </div>
  );
}
