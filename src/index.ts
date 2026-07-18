import { defineExtension } from "@tracht-digital-solutions/tds-panel-contract";

/**
 * Lexware billing-hub manifest. Admin-only extension: a customer/project
 * directory, time→invoice export (Lexware Office), and contact/lead push. No
 * `dependsOn` — it reads other extensions' data defensively at runtime (see the
 * PHP SourceGateway), so it composes on its own.
 */
export default defineExtension({
  id: "lexware",
  name: "Lexware",
  version: "0.1.0",
  permissions: [
    { id: "lexware:read", label: "Lexware / Rechnungen ansehen", group: "lexware" },
    { id: "lexware:write", label: "Lexware-Kunden, Kontakte & Rechnungen verwalten", group: "lexware" },
  ],
  nav: [
    {
      id: "lexware",
      label: "Lexware",
      href: "/lexware",
      icon: "receipt",
      group: "abrechnung",
      order: 20,
      permission: "lexware:read",
    },
  ],
  widgets: [
    {
      id: "lexware-invoices",
      title: "Lexware-Rechnungen",
      island: "@tracht-digital-solutions/tds-ext-lexware/widgets/Widget.astro",
      size: "sm",
      permission: "lexware:read",
      dataEndpoint: "/lexware/summary",
      order: 20,
    },
  ],
  settings: [
    {
      id: "lexware",
      label: "Lexware",
      island: "@tracht-digital-solutions/tds-ext-lexware/islands/Settings.astro",
      order: 20,
    },
  ],
  routes: [
    {
      pattern: "/lexware",
      entrypoint: "@tracht-digital-solutions/tds-ext-lexware/pages/Index.astro",
      permission: "lexware:read",
    },
  ],
  i18n: {
    de: {
      "lexware.title": "Lexware",
      "lexware.customers": "Kunden",
      "lexware.time": "Zeit zuordnen",
      "lexware.contacts": "Kontakte",
      "lexware.invoices": "Rechnungen",
    },
    en: {
      "lexware.title": "Lexware",
      "lexware.customers": "Customers",
      "lexware.time": "Assign time",
      "lexware.contacts": "Contacts",
      "lexware.invoices": "Invoices",
    },
  },
});
