=== Grandranker ===
Contributors: Grandranker
Tags: seo, ai, content, publishing, articles
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Automatically publish and update SEO articles from Grandranker with images, categories, metadata, and structured data.

== Description ==

[Grandranker](https://grandranker.com) is an AI-powered SEO platform that researches your niche, generates high-quality articles optimized for both traditional search engines and AI search (Google AI Overviews, ChatGPT, Perplexity, Claude, Gemini), and publishes them directly to your WordPress site.

This plugin connects your WordPress site to Grandranker so articles flow straight from the platform into your blog — no copy-pasting, no manual formatting.

**What Grandranker does for you:**

* Researches keywords and competitors in your niche
* Generates long-form, SEO-optimized articles with structured data (JSON-LD schema)
* Tracks your visibility across Google and AI search engines
* Publishes and updates articles on your WordPress site automatically

**How the plugin works:**

1. Install and activate the plugin.
2. Enter your Grandranker API token in **Grandranker** settings.
3. Click **Save & Connect**.
4. Articles published in Grandranker appear on your WordPress site automatically — with content, featured images, categories, meta descriptions, and structured data all handled for you.

**Plugin features:**

* Secure HMAC-SHA256 signature verification on every request
* Creates and updates WordPress posts with title, slug, content, excerpt, and featured image
* Injects JSON-LD structured data (schema markup) into page head for SEO
* Sets Yoast SEO meta description automatically
* Unpublish endpoint to remove articles remotely
* Ping endpoint for connectivity checks

== Installation ==

1. Upload the `grandranker` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → Grandranker**.
4. Enter your API token and domain ID from your Grandranker dashboard.
5. Click **Save & Connect**.

== Frequently Asked Questions ==

= Where do I find my API token and domain ID? =

Log in to [Grandranker](https://app.grandranker.com), go to your domain settings, and navigate to the Integrations section. Your API token and domain ID are displayed there.

= Is the connection secure? =

Yes. All article pushes are signed with HMAC-SHA256. The signing secret is exchanged during the initial connection and never transmitted again. Every incoming request is verified before any content is created.

= What happens when I publish an article in Grandranker? =

The article is sent to your WordPress site via a signed REST API call. The plugin creates a new WordPress post with the title, slug, HTML content, excerpt, meta description, featured image, and JSON-LD structured data. Re-publishing the same article updates the existing post.

= Can I unpublish articles from Grandranker? =

Yes. Grandranker can send a delete request to move the WordPress post to the trash.

== Changelog ==

= 1.7.1 =
* Fix: the page right after connecting no longer asks for a connection key.

= 1.7.0 =
* Connects by itself after activation — no key needed.

= 1.6.5 =
* Duplicate cleanup check for a post with more than a thousand image copies: the site is searched only for the copies being checked, so the check no longer times out. What counts as a copy that is safe to remove is unchanged

= 1.6.4 =
* Duplicate cleanup check for posts with many image copies: one post can now be checked over several short requests, so it no longer times out on slow hosts. What counts as a copy that is safe to remove is unchanged

= 1.6.3 =
* Faster duplicate cleanup check: the site is searched once per page of posts instead of once per image, with a simpler search, so the check no longer times out on sites with a large database or a slow host. What counts as a copy that is safe to remove is unchanged

= 1.6.2 =
* Updating an article from Grandranker keeps a published or scheduled post live. Before, with "Post status" set to Draft or Pending Review, every update turned a post you had already published back into a draft and took it off your blog

= 1.6.1 =
* Safer duplicate cleanup: a copy is removed only when Grandranker created it and nothing on the site uses it. Its id and its file name, in every size, must appear in no post content, post meta (page builders, galleries, SEO plugin images) or site option
* Body-image copying preview: Grandranker support can list which images an existing post would have copied before anything is changed
* Faster duplicate cleanup on large sites: the check runs a page of posts at a time and compares file sizes before reading files
* Articles with many images: one update copies up to 8 new images and the rest on the next update, so large articles no longer time out. Images in srcset (responsive sizes) are copied too

= 1.6.0 =
* Image reuse: each image from Grandranker is stored once in the Media Library. Before, every update of an article downloaded its featured image again and added another copy
* Body-image copying: images inside an article are copied into the Media Library and served from your site instead of Grandranker's image server
* Featured-image error reporting: when the featured image cannot be downloaded or attached, the plugin tells Grandranker why instead of leaving the post without an image unnoticed
* Schema-only updates: Grandranker can refresh an article's structured data (JSON-LD) without sending the post again, so edits made in WordPress stay as they are
* Duplicate cleanup: Grandranker support can remove the identical image copies earlier versions created, after reviewing the list. Only copies attached to Grandranker posts and used nowhere are removed
* The ping endpoint reports the plugin version

= 1.5.10 =
* When no SEO plugin is active, articles print their meta description and Open Graph tags. Before, the meta description from Grandranker never reached the page

= 1.5.9 =
* Updating an article from Grandranker keeps the post's original publish date. Before, every update gave the post today's date and moved it to the top of the blog

= 1.5.8 =
* New posts get an author when no Default author is set: the WordPress user whose display name matches the Grandranker author, otherwise the first administrator. Before, they had no author and no byline

= 1.5.7 =
* Unpublishing from Grandranker now only removes a post this plugin published for that article. If the stored post id points at anything else, such as a page or a media file after a site move, the plugin leaves it untouched and reports why
* Serve an IndexNow key file so newly published articles are crawled by Bing, Yandex, Naver, Seznam and Yep within minutes instead of waiting for a scheduled crawl
* Remove the IndexNow key when disconnecting the site
* Use the SEO title from Grandranker as the page title when no SEO plugin is active

= 1.5.5 =
* Fix article updates not applying: update deliveries were acknowledged but never written to the existing post, so edited content and corrected JSON-LD schema did not reach already-published articles. Updates now rewrite the matching post in place.

= 1.5.4 =
* Add a direct Settings link to the plugin row for faster configuration
* Improve connection guidance and keep the saved connection key visible in a masked field
* Highlight the connected state with a clear status badge
* Improve WordPress 7.0 compatibility and harden JSON-LD and media handling

= 1.5.3 =
* Fix corrupted JSON-LD schema: WordPress stripped backslashes on save, breaking escaped quotes and unicode characters (e.g. German umlauts) and producing invalid JSON in the page head
* Skip outputting stored schema that no longer parses as valid JSON, so pages published with older versions stop failing schema validation after updating

= 1.5.2 =
* Set the SEO plugin meta title on publish (Yoast / Rank Math / AIOSEO) so the SEO title is populated instead of empty

= 1.5.1 =
* Set the SEO plugin focus keyword on publish (Rank Math / Yoast) so keyword analysis scores correctly instead of showing an empty Focus Keyword

= 1.3.1 =
* Strip embedded JSON-LD schema from post content and inject via wp_head instead
* Update existing posts on re-publish instead of skipping
* Rename plugin from "Grandranker Publisher" to "Grandranker"

= 1.3.0 =
* Map article category to WordPress category on publish

= 1.0.0 =
* Initial release
* Secure HMAC-signed article publishing endpoint
* Automatic featured image attachment
* Yoast SEO meta description support
* Admin settings page for connection management
* Ping endpoint for connectivity verification
