=== FluentHive ===
Contributors: wpgaurav
Tags: newsletter, fluentcrm, email archive, substack import, beehiiv
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send better FluentCRM newsletters: a public archive of issues, a clean reading page in your site's font, paid tiers, and one-click email drafts.

== Description ==

FluentHive turns WordPress plus FluentCRM into a publication.

* **Issue pages** at `/newsletter/issue/{slug}/` with an archive and RSS feed. The URL base is configurable.
* **A clean reading page.** Each issue renders as the email it was, with its own header and footer, light/dark mode that follows the reader's system, reading time, previous/next navigation and a signup box. Your theme's styles and scripts are kept off these pages; other plugins keep working.
* **One-click FluentCRM drafts.** Publish an issue and FluentHive creates a draft campaign in FluentCRM, addressed to the lists you choose, with Unsubscribe and Manage subscription links. Nothing is ever scheduled or sent by FluentHive; you review and send in FluentCRM.
* **Mirroring.** Newsletters you write directly in FluentCRM publish as issue pages once sent. "View in browser" links go to the issue page.
* **Import from Substack**, including images, which are copied into your media library.
* **Nofollow for external links** inside issues (optional), Rybbit analytics support, custom CSS, logo, avatar, byline and accent colors.

Optional modules (off by default):

* **Consent policy** for Core Forms → FluentCRM signups: only reviewed forms with an exact consent disclosure can add contacts; opt-outs and suppression tags are always respected; consent evidence is stored on the contact.
* **FluentCart lifecycle tags**: purchase, refund and abandoned-checkout tags, plus category-based interest tags.
* **Paid subscriptions** sold through FluentCart: tiers ordered lowest to highest, each with its own FluentCRM list. Recurring plans follow the FluentCart subscription (renewals, failed payments with a grace period, cancellations that run to the end of the paid period, pauses, refunds); one-time plans give a fixed term that stacks on renewal, or lifetime access. Paid issues show a preview and the plans; members read in full after signing in or through an emailed one-time reading link. Drafts for paid issues go to the matching tier lists. Turning the module on starts you with a Free tier (your existing lists and signup form) and a Paid tier; "Create FluentCart product" on any tier builds a published product with monthly, yearly and optional lifetime prices and maps them in one click. Includes a Paid members screen with manual grants, `[fluenthive_plans]` and `[fluenthive_account]`.

FluentHive works without FluentCRM for the archive and reading pages; email features need FluentCRM.

== Privacy ==

No telemetry: FluentHive doesn't send usage statistics, your content or your visitors' data to gauravtiwari.org.

License and updates: when you activate a license, FluentHive sends the license key, the product ID, your site address and the plugin version, plus your WordPress and PHP versions, to the FluentCart store at gauravtiwari.org. It contacts the store again when you deactivate the license and when WordPress checks for updates. Nothing is sent before you enter a key. Update packages and update-screen images load from r2.gauravtiwari.org. Every feature works without a license.

Other outbound requests:

* Substack import fetches the publication you name and copies its images into your media library.
* Remote images in issue content are downloaded to your site.
* Rybbit analytics loads only if you add your own script URL and site ID.

Data stored on your site:

* `mh_reader` and `mh_member` cookies let paid readers open member-only issues.
* Share links are plain links with no third-party scripts.

Store privacy policy: https://gauravtiwari.org/privacy-policy/

== Installation ==

1. Upload the `fluenthive` folder to `/wp-content/plugins/` and activate it.
2. Go to **Newsletter → Settings** and set your newsletter name, sender, signup form and lists.
3. Write your first issue under **Newsletter → Write New Issue**.

== Frequently Asked Questions ==

= Does FluentHive send email? =

No. It creates draft campaigns in FluentCRM. Sending, unsubscribe handling and List-Unsubscribe headers stay with FluentCRM.

= Will my theme's design show on issue pages? =

No, by design. Issue pages use FluentHive's own templates so they read like the email. You can turn theme isolation off under Settings → Design.

= Can I import from Substack? =

Yes, from Settings → Tools or with `wp fluenthive import-substack <slug>...`.

== WP-CLI ==

    wp fluenthive import-substack <slug>... [--publication=<subdomain>] [--title=<title>] [--slug=<slug>] [--dry-run]
    wp fluenthive import-campaign <campaign_id> [--title=<title>] [--slug=<slug>]
    wp fluenthive relink
    wp fluenthive sync
    wp fluenthive seed
    wp fluenthive paid members|reconcile|sweep|grant|revoke|access [<args>]

== Changelog ==

= 1.1.0 =
* New Typography tab. Issue pages, the archive, and the newsletter page now use your site's own font by default, detected from the Functionalities fonts module or your theme.json, instead of a fixed font. You can also pick a system sans-serif, a book serif, or any custom font stack, and add your own font files.
* Set the reading size, line height, reading width, and body and heading weights without writing CSS.
* Italic accents on subtitles, captions, and quotes now switch on only when the font has a real italic, so fonts without one no longer get a slanted fake. You can force them on or off.
* Issue numbers: each issue shows "Issue #12", and the archive lists the number under the date.
* A thin reading-progress bar sits under the header on issue pages. It is pure CSS and skips browsers that don't support scroll-driven animations.
* The archive has a search box. Results keep the year grouping.
* An RSS link in the footer and a feed link in the page head point readers and feed apps to the issue feed.
* Optional cover image: show the featured image above the issue title.
* Reading speed for the "min read" estimate is now a setting.
* Printing an issue prints just the issue, with link addresses written out.
* The email version uses the reading font first, then system fonts.
* Automatic updates are on: activate a free license under Newsletter → Settings → License.

= 1.0.0 =
* First release. FluentHive is MailerHive rebuilt on FluentCRM: issues, the reading page, paid tiers, Substack import, one-click campaign drafts, campaign mirroring, the consent policy and FluentCart lifecycle tags all work against FluentCRM lists, tags and campaigns.
* `wp fluenthive migrate data` and `wp fluenthive migrate forms` move a MailerHive site across. Both are dry runs until you add `--apply`.

== Credits ==

Icons: Tabler Icons (MIT), bundled in `assets/icons`.
