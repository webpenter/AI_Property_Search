# AI Property Search for WordPress

Let visitors search listings by **describing what they want in plain English**: "3 bed house with a garden under 600k near the lake". An AI model turns the sentence into validated filters, WordPress runs a fixed, safe query, and the visitor lands on real results.

Works with **Houzez** and **Listeo** (verified), plus presets for **Homey, WPResidence, RealHomes** and a **custom mapping** for any other theme.

> Built by [WebPenter](https://github.com/webpenter).

## Demo

<video src="https://github.com/webpenter/AI_Property_Search/raw/main/docs/demo.mp4" controls muted width="100%"></video>

If the player doesn't load, [download the video](docs/demo.mp4).

## Features

- **Natural-language search**: type or speak a request; the AI extracts location, type, status (sale/rent), price, bedrooms, bathrooms, garages, features and radius.
- **Safe by design**: the model never writes a query. Its output is checked against an allowlist and the site's real taxonomies, then a fixed, parameterised search runs. Nothing from the model reaches a query string.
- **Only offers what exists**: types, features, statuses and locations are read from your theme's real taxonomies, so the AI can't invent a filter.
- **Example prompts from your listings**: suggested searches are built from real listings, so they always return results.
- **Follow-up refinement**: each message adjusts the previous search instead of starting over, and the visitor can clear fields.
- **Works without AI too**: if the key is missing or the AI is down, a deterministic offline parser handles the request.
- **Two AI providers**: Groq as the main model and Gemini as backup. Keys are write-only and never leave PHP.
- **Floating search button on every public page** (or a shortcode), with results shown on the theme's own results page or inline.
- **Lead capture**: a consent-based inquiry form stores leads in your database, with an optional email notification to you. The visitor is never emailed.
- **Seller call-to-action**: when a visitor says they want to *sell*, show your own message and button (fully editable).
- **Rate limiting** per visitor and a 500-character message cap.
- **Theme adapters**: add support for another theme with a preset or the Custom mapping screen, with no new search code.

## Theme support

| Theme | Listing post type | Status |
|---|---|---|
| **Houzez** | `property` | Verified on a real install |
| **Listeo** | `listing` | Verified on a real install |
| **Homey** | `listing` | Best-guess mapping, unverified |
| **WPResidence** | `estate_property` | Best-guess mapping, unverified |
| **RealHomes** | `property` | Best-guess mapping, unverified |
| **Any other theme** | your choice | Fill in the **Custom mapping** in Settings |

The settings screen shows which theme was detected and what the plugin can filter on. For unverified themes, check one search after setup and correct any slug or meta key in the **Mapping (advanced)** section.

## How it works

1. The visitor types a request in the search panel.
2. The plugin sends it to Groq (or Gemini, or the offline parser) and gets back a *delta*: what this message changes.
3. The delta is validated against an allowlist and your site's real taxonomies, then merged with the previous search.
4. A fixed, parameterised query runs. The visitor is sent to the theme's results page, to the single matching listing, or sees results inline.

## Requirements

- WordPress 5.8+ and PHP 7.4+
- A listing theme from the table above (or a custom mapping)
- A free [Groq API key](https://console.groq.com/keys) (optional but recommended; a [Gemini key](https://aistudio.google.com/apikey) is an optional backup)

## Setup

No theme files are edited.

### 1. Install

1. Click **Code → Download ZIP** (or `git clone https://github.com/webpenter/AI_Property_Search.git`).
2. Put the files in a folder named `spot-ai-search` and zip it so that folder is the top level of the ZIP.
3. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the ZIP and click **Activate**.
   Or copy the folder to `wp-content/plugins/` over FTP and activate it.

The floating **AI Search** button now appears on your public pages.

### 2. Add your AI key

Open **SPOT AI Search → Settings** and paste your Groq API key (and optionally a Gemini key). Or define it in `wp-config.php`:

```php
define( 'SPOT_AI_GROQ_KEY', '...' );   // see includes/class-groq.php for all supported constants
```

### 3. Check the theme

Still in Settings, the **Theme** line shows what was detected. Leave it on *Automatic*, pick a preset, or fill in the **Mapping (advanced)** fields (post type, taxonomies, meta keys) for a custom theme.

### 4. Choose which listings are searched

**Listings searched** has three options: automatic (labelled sample listings while any exist, otherwise everything), every published listing, or only the sample listings.

### 5. Optional settings

| Setting | What it does |
|---|---|
| Seller link / message / button | The call-to-action shown when a visitor wants to sell |
| Email new inquiries to | Sends you a notification for each inquiry |
| Searches per minute, per visitor | Rate limit (default 40, range 3 to 200) |

### 6. Place it where you want

- **Everywhere (default)**: a floating button on every public page. Turn it off with `define( 'SPOT_AI_AUTO_PLACE', false );`
- **On one page**: use the shortcode, with results shown inline or by redirecting to the theme's results page:

```
[spot_ai_search]
[spot_ai_search mode="redirect"]
```

Inquiries appear under **SPOT AI Search** in wp-admin.

## Branding and defaults to change

This plugin came from a client demo, so a few defaults are example values. Change them before using it on your own site:
- **Seller message, link and button** (Settings): the defaults point to an example realty site.
- **Brand name**: set `define( 'SPOT_AI_BRAND', 'Your Brand' );` in `wp-config.php`.
- **"Near the lake" searches** are centred on Lake Zurich, IL by default. Override with `SPOT_AI_LAKE_LAT` and `SPOT_AI_LAKE_LNG`.

## Structure

```
spot-ai-search.php      plugin bootstrap, shortcode, floating button
includes/
  class-adapter.php        theme presets and custom mapping
  class-catalog.php        what the site really has (types, features, cities)
  class-criteria.php       allowlisted schema, validation, merging
  class-groq.php           Groq and Gemini clients
  class-fallback-parser.php  offline interpreter
  class-search.php         fixed, parameterised search
  class-houzez-bridge.php  hand-off to the Houzez results page
  class-inquiry.php        consent-based lead capture
  class-rest.php           REST API (spot-ai/v1)
  class-settings.php       admin settings
  class-sample-data.php    fictional sample listings for demos
assets/                 search panel CSS and JS
```

## License

MIT. See [LICENSE](LICENSE).
