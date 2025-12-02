=== WP API Monitor ===
Contributors: geodefender
Tags: api, log, rest api, monitor, debugging, woocommerce, developer tools
Requires at least: 5.2
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight and powerful API logger for WordPress. Monitor, search, export and debug all REST API requests and responses in real time.

== Description ==

WP API Monitor is a developer-friendly tool that logs, visualizes and filters every REST API request made inside your WordPress site — including WooCommerce and custom endpoints.

Perfect for debugging integrations, monitoring API health, auditing suspicious requests or understanding what your plugins/themes are doing behind the scenes.

### 🔍 Key Features

- **Full Request Logging**  
  Method, route, headers, payload, response code, body and execution time.

- **Real-Time Dashboard**  
  Instant view of incoming/outgoing API calls.

- **Advanced Filters**  
  Filter by route, IP address, method, response code, plugin source or date range.

- **CSV Export**  
  Export logs for auditing, compliance or external analysis.

- **Retention Controls**  
  Set how long logs are stored (7/30/90 days or custom).

- **Extended Debug Mode**  
  Capture full request/response bodies for deep debugging.

- **Route Blocking Helper**  
  Identify endpoints receiving unusual traffic so you can block them easily.

- **WooCommerce Compatible**  
  Automatically detects and logs all Woo API requests.

### 🚀 Why This Plugin?

Debugging REST API traffic in WordPress is usually a pain.  
With WP API Monitor you can:

- Understand which plugin is generating requests.
- Detect API failures or repeated 400/500 responses.
- Catch WooCommerce checkout or webhook issues.
- Audit user actions from external systems.
- Analyze suspicious or malicious requests.

Built for developers, agencies and DevOps teams.

== Installation ==

1. Upload the plugin folder `wp-api-monitor` to `/wp-content/plugins/`.
2. Activate it through the “Plugins” menu in WordPress.
3. Go to **Tools → API Monitor** to view logs.

== Screenshots ==

1. API request log dashboard.
2. Single request details.
3. Advanced filters panel.
4. CSV export screen.
5. Retention settings.

== Changelog ==

= 1.0.0 =
* Initial release.

== Frequently Asked Questions ==

= Does this slow down my site? =  
No — logs are lightweight and stored efficiently. Extended debug mode can be toggled on/off.

= Can I disable certain routes from being logged? =  
Yes. A route exclusion list is included.

= Does it work with WooCommerce? =  
100% compatible.

= Is data sensitive? =  
You can mask or exclude payloads to comply with GDPR/security requirements.
