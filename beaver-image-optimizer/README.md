# Beaver Image Optimizer

Converts JPEG and PNG uploads to WebP using only the imaging libraries already
bundled with PHP. Built for shared hosting: no root access, no command line, no
external service.

---

## Install

1. Copy the `beaver-image-optimizer` folder into `wp-content/plugins/` (or
   upload the zip under **Plugins → Add New → Upload**).
2. Activate it.
3. Go to **Beaver Optimizer → Dashboard** and check the **Server health** panel.
4. Go to **Bulk Optimize** and run **Optimize all images**.

New uploads are converted automatically from activation onwards.

## How delivery works

Each converted image is written as a sidecar next to the original — `photo.jpg`
becomes `photo.jpg.webp`. Your HTML keeps pointing at `photo.jpg`, and a rewrite
rule scoped to the uploads folder swaps in the WebP version when the browser
sends an `Accept: image/webp` header.

Because no URL in the page ever changes, `srcset`, responsive images, Gutenberg
blocks, Elementor widgets and WooCommerce galleries all keep working exactly as
before.

The rules go in `wp-content/uploads/.htaccess` rather than the site root, so a
rejected directive can never take down the whole site.

## When something fails

Every image that cannot be converted records **why**, in plain language, shown
in the WebP column of the media library and on the dashboard with a Retry
button. The reason comes from the image editor itself rather than a generic
message.

A run that is killed outright — memory limit, execution timeout — is attributed
to the image that caused it, which is then dropped from the queue and skipped,
so a single problem image cannot stall a library of hundreds.

## Working on shared hosting

The bulk runner shares one time budget across each request, checks it before
starting another image rather than after, and passes the remaining time down so
a slow image degrades to "partial" instead of taking the request with it.

On Imagick hosts, ImageMagick is given explicit memory and map limits so a large
image spills to a temporary disk cache rather than exhausting the container.
Where those limits cannot be applied, a pixel estimate sized for the build's
quantum depth is used instead.

## Settings worth knowing

| Setting | Notes |
|---|---|
| **Quality preset** | High (85%), Balanced (75%), Maximum compression (60%). Balanced suits most sites. |
| **Keep originals** | On by default. Turning it off deletes the source once the WebP is verified on disk — there is no undo. |
| **Downscale oversized** | Applies to the WebP copy only; the original is untouched. |
| **Picture element fallback** | Only needed where rewrite rules are not read, such as Nginx. Run the delivery test first. |
| **Bulk batch size** | Lower it if your host times out. |

## WP-CLI

```sh
wp beaver-io optimize
wp beaver-io status
wp beaver-io rules
```

## Why some images are skipped

* The WebP came out larger than the original — common with flat-colour PNG
  graphics, where keeping the original is the right answer.
* The image was too large to decode inside the PHP memory limit.
* The file type is switched off in the settings.

Hover the entry in the bulk log, or read the media library column, for the exact
reason on a given image.

## Deactivating and uninstalling

Deactivation removes the rewrite rules only. Image files and statistics are
preserved. Uninstalling also removes the plugin's options and per-attachment
metadata, but still leaves image files alone — in "delete originals" mode the
WebP is the only remaining copy.

## Requirements

WordPress 5.8+, PHP 7.4+, and either GD with `imagewebp()` or Imagick with WebP
support. Apache or LiteSpeed for the rewrite-based delivery; Nginx sites should
use the picture element fallback.

---

Digital Beaver · GPL-2.0-or-later
