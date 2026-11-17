# FluentHive

Send better [FluentCRM](https://fluentcrm.com/) newsletters. FluentHive gives WordPress Beehiiv- and Ghost-style publishing: a public archive of issues, a clean reading page in your site's font, paid tiers, and one-click email drafts.

## Features

- **Issue pages** at `/newsletter/issue/{slug}/`, an archive at `/newsletter/issue/` and an RSS feed. The URL base is configurable.
- **A clean reading page.** Each issue renders as the email it was, with its own header and footer, light/dark mode that follows the reader's system (with a toggle), reading time, previous/next navigation and a signup box. Theme styles, scripts and hooks are kept off these pages; WordPress core and other plugins keep working.
- **One-click FluentCRM drafts.** Publishing an issue (or clicking *Create FluentCRM draft*) creates a draft campaign addressed to the lists you choose, with Unsubscribe and Manage subscription links. FluentHive never schedules or sends; you review and send in FluentCRM.
- **Mirroring.** Newsletters written directly in FluentCRM publish as issue pages once sent to a mirrored list. FluentCRM "view in browser" links redirect to the issue page.
- **Substack import**, with images copied into the media library and links between imported issues rewritten.
- **Typography**: your site's font by default, or a system, serif or custom stack with your own font files; reading size, line height, width and weights.
- **Reading extras**: issue numbers, archive search, a CSS-only reading-progress bar, RSS links, optional cover images and print styles.
- **Branding and settings**: logo (with a dark-mode variant), avatar, byline, sender line, accent colors, header and footer links, custom CSS, nofollow for external links, Rybbit analytics.
- **`[fluenthive_issues limit="6"]`** shortcode to list recent issues anywhere.

### Optional modules (off by default)

- **Consent policy** for Core Forms → FluentCRM signups. Only registered forms with an exact consent disclosure can add contacts; opt-outs and suppression tags are respected; consent evidence (form, time, version, disclosure) is saved on the contact. Unregistered forms fail closed.
- **FluentCart lifecycle tags**: purchase, refund and abandoned-checkout tags, plus product-category interest tags.
- **Paid subscriptions** sold through FluentCart: tiers ordered lowest to highest, each with its own FluentCRM list. Recurring plans follow the FluentCart subscription (renewals, failed payments with a grace period, cancellations that run to the end of the paid period, pauses, refunds); one-time plans give a fixed term that stacks on renewal, or lifetime access. Paid issues show a preview and the plans; members read in full after signing in or through an emailed one-time reading link. Drafts for paid issues go to the matching tier lists. Turning the module on starts you with a Free tier (your existing lists and signup form) and a Paid tier; "Create FluentCart product" on any tier builds a published product with monthly, yearly and optional lifetime prices and maps them in one click. Includes a Paid members screen with manual grants, `[fluenthive_plans]` and `[fluenthive_account]`.

## Requirements

- WordPress 6.6+, PHP 8.1+
- FluentCRM (and FluentCRM Pro for some features) for email drafts and mirroring. The archive and reading pages work without it.
- Core Forms for the signup form and the consent module (optional).
- FluentCart for paid subscriptions (optional).

## Updates and license

FluentHive updates itself through the normal WordPress plugin screen once a free license is active under **Newsletter → Settings → License**. Get a key at [gauravtiwari.org/product/fluenthive](https://gauravtiwari.org/product/fluenthive/). Every feature works without a license; only automatic updates need one.

## Privacy

No telemetry: FluentHive doesn't send usage statistics, your content or your visitors' data to gauravtiwari.org.

License activation sends the license key, product ID, site URL, plugin version, WordPress version and PHP version to gauravtiwari.org, and activated sites contact the store when WordPress checks for updates. Update packages and update-screen images load from r2.gauravtiwari.org.

Substack import, remote image copying and the optional Rybbit script run only when you use them. Paid access sets `mh_reader` and `mh_member` cookies.

The store's [privacy policy](https://gauravtiwari.org/privacy-policy/) covers license data.

## Installation

1. Download the latest release zip, or clone this repo into `wp-content/plugins/fluenthive`.
2. Activate **FluentHive**.
3. Open **Newsletter → Settings**: set the newsletter name, sender, signup form and lists.
4. Write an issue under **Newsletter → Write New Issue**.

## WP-CLI

```
wp fluenthive import-substack <slug>... [--publication=<subdomain>] [--title=<title>] [--slug=<slug>] [--dry-run]
wp fluenthive import-campaign <campaign_id> [--title=<title>] [--slug=<slug>]
wp fluenthive relink
wp fluenthive sync
wp fluenthive seed
wp fluenthive paid members|reconcile|sweep|grant|revoke|access [<args>]
```

## Building a release

```
bin/build-zip.sh
```

Creates `dist/fluenthive-{version}.zip` from the committed tree (development files are excluded via `.gitattributes`).

## License

GPL-2.0-or-later. Icons are from [Tabler Icons](https://tabler.io/icons) (MIT), see `assets/icons/LICENSE`.
