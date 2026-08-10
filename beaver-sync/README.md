# Beaver Sync

Brings the live site's media down to a local copy over HTTPS. **No SSH, no FTP,
and nothing is ever written to the live site.**

The live site publishes one read-only list of what it holds. The copy compares
that against its own uploads folder, shows you the difference, and downloads
only that part of it.

---

## Install

The same plugin goes on both sites and does opposite jobs, chosen by a role.

**On the live site**

1. Upload, activate, **Tools → Beaver Sync**.
2. Choose *The live site*, save.
3. Copy the sync key it shows.

**On the local copy**

1. Upload, activate, **Tools → Beaver Sync**.
2. Choose *The local copy*, paste the live address and the key, save.
3. **Check for differences**, read it, then **Copy these files**.

Put the key in the live site's `wp-config.php` and it never reaches the
database or any database export:

```php
define( 'BEAVER_SYNC_KEY', 'your-long-random-key' );
```

## The safety model

This is the part worth reading, because it is the reason the plugin is shaped
the way it is.

| | |
|---|---|
| **The live site is never written to** | One route, `GET /wp-json/beaver-sync/v1/manifest`, and it returns a list. There is no route that accepts a file. A leaked key exposes a media inventory, not the site. |
| **Nothing local is ever deleted** | Files you have that the server does not are counted, shown, and left alone. |
| **Media only** | An allowlist of extensions, checked again at the moment of writing. A source that had been tampered with still cannot put PHP into your uploads folder. |
| **No database** | Content stays production's. |
| **Dry run first** | Checking changes nothing. You see counts, a file list and a total size before anything downloads. |

The reason a push tool does not exist here: a sync endpoint that can write files
into production is remote code execution with a friendly name on it. Pulling
media needs no such thing, because the files are already public. So it does not
have one.

## How a difference is decided

By **size**, which is what rsync's quick check does and for the same reason.
Media is written once and never edited in place, so size is a strong signal, and
hashing hundreds of megabytes to answer one HTTP request is a good way to be
killed by a shared host's execution limit.

Modified times are deliberately ignored. The same file copied between two
machines routinely arrives with a new timestamp, and treating that as a change
would mean re-downloading the entire library on every run, for ever.

## Large libraries

Three thousand images will not arrive inside one PHP request on shared hosting,
so the browser does it in batches of eight with a progress bar and resumes where
it stopped. For a first pull of a big library, use the command line and let it
run:

```sh
wp beaver-sync pull           # dry run, the default
wp beaver-sync pull --live    # copy
wp beaver-sync pull --live --batch=20
wp beaver-sync key            # print this site's key, on a source
```

## Hooks

| Hook | Purpose |
|---|---|
| `beaver_sync_extensions` | The list of extensions the plugin will carry. Add to it for a file type you use; the default list covers images, video, audio and documents. |

## What it does not do, and where that work belongs

* **Code.** Use the update channel in this repository. A version number records
  what is installed on which site; a file copy does not.
* **The database.** Production is where the client writes. Pulling a database
  down is a separate job with different risks, and pushing one up discards their
  work.

## Requirements

WordPress 5.8+, PHP 7.4+, and the two sites able to reach each other over HTTPS.

---

Digital Beaver · GPL-2.0-or-later
