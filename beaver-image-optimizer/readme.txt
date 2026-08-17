=== Beaver Image Optimizer ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: webp, image optimization, performance, compression, lazy loading
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts JPEG and PNG uploads to WebP using only the imaging libraries already bundled with PHP. Built for shared hosting.

== Description ==

Beaver Image Optimizer converts your media library to WebP and serves it automatically to browsers that support it. Everything runs inside PHP through the WordPress image editor API, so there is nothing to install on the server: no command line binaries, no Docker, no external optimization service, and no root access.

**How delivery works**

Each converted image is written as a sidecar file next to the original — `photo.jpg` becomes `photo.jpg.webp`. Your HTML keeps pointing at `photo.jpg`, and a rewrite rule scoped to the uploads folder swaps in the WebP version when the browser sends an `Accept: image/webp` header.

Because no URL in the page ever changes, WordPress `srcset`, responsive images, Gutenberg blocks, Elementor widgets and WooCommerce product galleries all keep working exactly as they did before.

**Features**

* Automatic conversion of JPEG and PNG images on upload, including every registered thumbnail size.
* Bulk conversion for an existing media library, processed in small resumable batches.
* Three quality presets: High (85%), Balanced (75%) and Maximum compression (60%).
* Keep originals as a backup, or delete them after a verified conversion to reclaim disk space.
* Optional downscaling of oversized full size images in the WebP copy only.
* Native lazy loading with a configurable exclusion for the first images on a page, so the largest contentful paint is never delayed.
* Duplicate optimization prevention — an image is never converted twice unless you ask for it.
* Automatic rejection of conversions that come out larger than the original, which is common with flat colour PNG graphics.
* Memory pre-flight check that skips images too large for the PHP memory limit instead of crashing the request.
* Statistics for original size, optimized size, saved bytes and average compression percentage.
* A WebP column in the media library with per-image optimize and remove actions.
* Plain-language failure reporting: every image that cannot be converted records why, shown in the media library and on the dashboard with a Retry button.
* Live delivery self-test that makes a real HTTP request to confirm WebP is reaching browsers.

== Installation ==

1. Upload the `beaver-image-optimizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to **Beaver Optimizer → Dashboard** and check the Server health panel.
4. Go to **Beaver Optimizer → Bulk Optimize** and run **Optimize all images**.

New uploads are converted automatically from the moment the plugin is activated.

== Frequently Asked Questions ==

= Will this work on Namecheap shared hosting? =

Yes. Namecheap cPanel accounts run Apache or LiteSpeed, both of which read `.htaccess` and support `mod_rewrite`, and every cPanel PHP build ships GD with WebP support. The plugin writes its rules to `wp-content/uploads/.htaccess` rather than the site root, so a rejected directive can never take down the whole site.

= Does it break Elementor, WooCommerce or Gutenberg? =

No. In the default "keep originals" mode, no image URL in your HTML is modified — the swap happens at the web server level. Attachment IDs, image sizes and `srcset` values are all untouched.

= What happens on Nginx? =

Rewrite rules are not read on Nginx. Run the delivery test on the dashboard; if it reports a failure, enable **Picture element fallback** in the settings and post content images will be wrapped in a `<picture>` element instead.

= Can I undo "delete originals"? =

No. That mode removes the source file once the WebP version is verified on disk, which is the point of the setting. Use "keep originals" unless disk space is genuinely the constraint.

= Why were some of my images skipped? =

Three common reasons: the WebP version was not smaller than the original (usual for flat colour PNG graphics), the image was too large to decode inside the PHP memory limit, or the file type is disabled in the settings. Hover the entry in the bulk optimizer log to see the exact reason.

= Does it delete my WebP files when I deactivate the plugin? =

No. Deactivation removes only the rewrite rules. Image files and statistics are preserved. Uninstalling removes the plugin's options and metadata but still leaves image files alone, because in "delete originals" mode the WebP file is the only remaining copy.

== Screenshots ==

1. Dashboard with optimization statistics and server health checks.
2. Bulk optimizer running in resumable batches.
3. Settings screen with quality presets and original handling.
4. WebP status column in the media library.

== Changelog ==

= 1.3.6 =
* Fixed "delete originals" mode becoming a dead end. Once an attachment's original was removed, its mime type became image/webp, which the plugin's own file-type filter then excluded from every future path — auto-optimize on a new thumbnail size, a bulk rescan, even pressing Optimize on it by hand. Attachments this plugin converted are now recognised by their own status record and stay reachable.
* Fixed the "recent errors" list on the dashboard sorting by an attachment's post-modified date rather than when it actually failed, since recording a failure never touches that column. A library with more failures than fit on screen could permanently hide a fresh one behind an older attachment that happened to have a later post edit. It now sorts by the failure's own recorded time.
* Two bulk-optimize runs started at once — a second browser tab, or the dashboard racing a WP-CLI pass — could shift overlapping images off the same stored queue and silently overwrite each other's progress. A batch now claims a short-lived lock before touching the queue and yields to whichever run holds it, self-clearing within seconds if a run ever dies mid-batch.
* The uploads-directory boundary check no longer treats every path as outside uploads when realpath() itself fails, which some restrictive open_basedir configurations do even for paths the plugin can legitimately read and write. It falls back to a normalised comparison that still rejects path traversal.

= 1.3.5 =
* Fixed thumbnail sizes added after an image was already marked optimized being skipped forever. A theme switch, a page builder, or Regenerate Thumbnails can register a new size on an attachment that finished its first pass long ago; the plugin used to check only the full-size image before deciding there was nothing left to do, so that new size sat unconverted with no error and no way to catch it short of forcing a full re-optimization. Every current size is now checked before an attachment is treated as finished.

= 1.3.4 =
* Fixed crash reports inventing a cause. Any unfinished request was reported as "ran out of memory or time", which produced the nonsense of a 176 x 50 logo being blamed for exhausting memory. When PHP records a fatal error its text is now quoted verbatim; when it records nothing, the report says the request was interrupted instead of guessing.
* Pressing Stop no longer leaves a marker behind for the next run to blame an image for.
* An in-flight marker older than an hour is discarded rather than attributed, so a marker left by an abandoned run cannot fail an image that has since converted.

= 1.3.3 =
* Fixed the memory pre-flight doing nothing on Imagick hosts. It returned early for Imagick on the grounds that Imagick allocates outside PHP's memory limit, which left the engine most shared hosts actually use with no guard at all — a large image went straight to conversion and took the request down with it.
* ImageMagick is now given explicit memory and map limits, so a large image spills to a temporary disk cache and converts slowly instead of exhausting the container. Big images that previously crashed now finish.
* Where those limits cannot be applied, the pixel estimate is used instead, sized for Imagick's quantum depth rather than GD's four bytes per pixel.
* A crash report now names the image's dimensions and megapixels, so it is clear whether the image needs resizing or the server needs a higher memory limit.

= 1.3.2 =
* Fixed the bulk optimizer repeating the same crash forever. The queue is only written once a batch finishes, so a request killed mid-image left that image at the head of the stored queue; 1.3.1 marked it failed but did not remove it, so resuming served the same image and died again. It is now dropped from the queue and counted, so the run always moves forward.
* The batch endpoint no longer answers with a bare HTTP 500. A memory reserve is held back and released during shutdown, which is enough to attribute the crash to the image that caused it and reply with a normal batch response.
* The browser now retries once after a failed batch instead of stopping the whole run, so a single unconvertible image no longer ends a job of hundreds.

= 1.3.1 =
* Fixed bulk optimization dying with an HTTP 500 part way through a large library. Every attachment was given a fresh full time budget while the request itself has only one execution limit to spend, so a batch of slow images overran max_execution_time and PHP killed the request.
* The batch now shares one budget across the request, checks it before starting another image rather than after, and passes the remaining time down so a slow image degrades to "partial" instead of taking the request with it.
* A request killed by the memory limit or the timeout is now attributed: the image being converted is recorded, reported as failed on the next run, and skipped, so a single problem image can no longer stall the whole queue.

= 1.3.0 =
* Failures now report why. The reason returned by the image editor was previously discarded and every failure read as "No files could be converted."
* The reason is stored on the attachment, so it survives the request and appears in the WebP column of the media library.
* Added an "Images that could not be optimized" panel to the dashboard, listing recent failures with the reason, the time and a Retry button. It renders only when something has actually failed.
* A successful retry clears the stored error, so a fixed image drops off the list.

= 1.2.0 =
* Added the Digital Beaver maker's mark to the Dashboard, Bulk Optimize and Settings screens. Admin only — nothing renders on the front end.

= 1.1.0 =
* Added WP-CLI commands: `wp beaver-io optimize`, `wp beaver-io status` and `wp beaver-io rules`. Bulk conversion no longer has to be driven from a browser tab.
* Fixed the settings runtime cache being cleared before the new values were written, so a save could leave superseded settings cached for the rest of the request.
* Fixed already-optimized attachments being reported as "skipped" in the bulk optimizer log.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.4 =
Crash reports now quote the real PHP error instead of guessing at a cause.

= 1.3.3 =
Large images that crashed the optimizer on Imagick hosts now convert instead.

= 1.3.2 =
Required if you saw HTTP 500 during bulk optimization: fixes the crash repeating on resume.

= 1.3.1 =
Fixes bulk optimization failing with an HTTP 500 on large media libraries.

= 1.3.0 =
Failed images now show why they failed, in the media library and on the dashboard.

= 1.2.0 =
Adds the Digital Beaver credit to the plugin's admin screens.

= 1.1.0 =
Adds WP-CLI support for bulk conversion and fixes two reporting bugs.

= 1.0.0 =
Initial release.
