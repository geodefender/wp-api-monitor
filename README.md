# WP API Monitor

WP API Monitor logs and visualizes every REST API request that reaches your WordPress or WooCommerce store. It captures payloads, status codes, requester IP details, and supports CSV exports so site owners can audit third‑party integrations with confidence.

## Features
- **Comprehensive logging:** Record method, endpoint, key owner, payloads, and responses for REST requests.
- **Search and filter tools:** Narrow results by date, method, endpoint contents, API key, or response code.
- **CSV exports:** Download filtered logs for external analysis or compliance reports.
- **IP enrichment:** Store ISP and geo data alongside each request to quickly spot suspicious traffic.
- **Retention controls:** Configure retention days or maximum rows with automated cleanup via WP-Cron.
- **Route blocking helper:** Define endpoints to ignore so sensitive or noisy routes stay out of the log.
- **Extended capture mode:** Optionally track all WordPress REST namespaces beyond WooCommerce.

## How it helps
WP API Monitor provides a transparent audit trail for API traffic, making it easier to troubleshoot failed calls, verify partner usage, and detect anomalous access patterns before they become incidents.

## Enabling GitHub-powered updates
WP API Monitor can pull new releases directly from the public repository at `geodefender/wp-api-monitor` on GitHub. To enable this:

1. Create a GitHub personal access token with read-only scope (for public repos `public_repo` is enough).
2. In WordPress, go to **WP API Monitor** → **Ajustes** and paste the token in the **Actualizaciones desde GitHub** section.
3. Save the settings. The token is stored in WordPress options (not in files) and used only for authenticated requests to `api.github.com`.
4. Check **Dashboard → Updates** to trigger WordPress to fetch the latest release metadata and install updates like any other plugin.
