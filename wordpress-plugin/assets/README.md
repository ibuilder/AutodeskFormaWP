# WordPress.org directory assets

These images are for the plugin's **SVN `assets/` directory**, which the
directory uses to render the listing page. They must **never** appear in the
distributed plugin zip.

That is why they live here, one level above the plugin folder: `bin/build-zip.sh`
archives only `publisher-for-autodesk-forma/`, so these cannot be swept in by
accident.

| File | Size | Purpose |
|---|---|---|
| `icon-128x128.png` | 128×128 | Search results and the plugin card |
| `icon-256x256.png` | 256×256 | Retina icon |
| `banner-772x250.png` | 772×250 | Listing page header |
| `banner-1544x500.png` | 1544×500 | Retina banner |

## Regenerating

```bash
php bin/build-assets.php
```

Everything is drawn from primitives by that script, so the artwork is original
and GPL-compatible — no stock imagery, which is a genuine rejection cause. The
banners deliberately contain no WordPress logo, which the guidelines forbid.

The script picks a system font automatically. Override if needed:

```bash
FORMA_FONT_BOLD=/path/to/Bold.ttf FORMA_FONT_REGULAR=/path/to/Regular.ttf \
  php bin/build-assets.php
```

## Publishing them

After the plugin is approved you get SVN access. Assets go in their own
top-level directory, alongside `trunk/` and `tags/`:

```bash
svn co https://plugins.svn.wordpress.org/publisher-for-autodesk-forma
cd publisher-for-autodesk-forma
cp /path/to/wordpress-plugin/assets/*.png assets/
svn add assets/* --force
svn ci -m "Add directory assets"
```

## Screenshots

Not generated here. Screenshots must show the actual plugin in use, so they have
to be captured from a real WordPress install:

1. `screenshot-1.png` — Forma → Overview, showing the operator dashboard.
2. `screenshot-2.png` — Forma → Review, with an update held for review.
3. `screenshot-3.png` — Forma → Connections.
4. `screenshot-4.png` — A published project page on the front end.

Each needs a matching numbered line under `== Screenshots ==` in `readme.txt`.
Add that section when the images exist — describing screenshots that are not
there is worse than having none.
