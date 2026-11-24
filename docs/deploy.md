# Deployment — leads.4-return.com

## Hosting facts (confirmed 2026-11-24)

| Item | Value |
|---|---|
| Panel | Plesk (customer account, **no API access** — both REST and XML API return permission denied) |
| Server OS | **Windows / IIS** (ASP.NET, ODBC, Web Deploy present; no SSH) |
| Subscription system user | `vecanetc` |
| Site | `leads.4-return.com`, IP `192.250.231.17` |
| Deploy path | `\leads.4-return.com` |
| Available tools | Git, Scheduled Tasks, PHP, Databases, SSL/TLS, Laravel (app installer) |

## How code reaches the server

1. Push to `main` on GitHub (`sergioveca/leads`).
2. GitHub webhook (push event) → Plesk Git `web-hook.php`.
3. Plesk pulls with its repo-specific read-only deploy key (`leads`) and deploys **automatically** to `\leads.4-return.com`.

No PC, SSH or panel login is needed for normal deploys.

Because the server has no SSH/Composer, the app is shipped ready to run (dependencies committed) and applies its own database migrations after a deploy.

## One-time items still to do

- [ ] Document root → `leads.4-return.com\public` (Hosting & DNS → Hosting settings)
- [ ] PHP 8.x on the site
- [ ] Let's Encrypt certificate
- [ ] Database created; credentials placed in the server-side `.env` (never in Git)
- [ ] Scheduled Tasks for data imports and skip tracing
