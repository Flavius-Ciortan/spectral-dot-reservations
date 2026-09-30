# WordPress.org Directory Assets

This repository folder contains WordPress.org directory artwork and its editable vector masters. It is excluded from the release ZIP. After the plugin is approved, copy the PNG files from this folder to the top-level `/assets` directory of the assigned WordPress.org SVN repository, not to `/trunk/assets`. Do not upload the `source/` directory.

## Supported Assets

Directory artwork is optional for initial code submission. Use these exact names when publishing it:

### Icons
- `icon-128x128.png` - Small icon (128x128px)
- `icon-256x256.png` - Large icon (256x256px)

### Banners
- `banner-772x250.png` - Standard banner (772x250px)
- `banner-1544x500.png` - Retina banner (1544x500px)

### Screenshots

The screenshot captions in `readme.txt` match these sequential files:
- `screenshot-1.png` - Plugin settings page
- `screenshot-2.png` - Product page with Reserve button
- `screenshot-3.png` - Reservation modal
- `screenshot-4.png` - My Account reservations page
- `screenshot-5.png` - Admin reservations dashboard
- `screenshot-6.png` - Basic reservation analytics

## Design Guidelines

The PNG assets use the established blue, navy, and amber palette. Icons contain no small text and remain identifiable at 128 pixels. Banners keep essential content in the central safe area. Screenshots show the rebranded Free plugin on a local test site using demonstration records. Recheck screenshots against the final release candidate before publication.

Editable masters are stored in `source/icon.svg` and `source/banner.svg`. Re-render both required dimensions after changing a master and visually inspect the standard and high-resolution outputs before publication.

## Upload Instructions

When submitting to WordPress.org:
1. Do not include these assets in the plugin ZIP.
2. Upload them to the WordPress.org SVN repository's top-level `/assets` directory.
3. Keep screenshot numbering identical to the captions in `readme.txt`.
4. Confirm image dimensions and file-size limits against the current Plugin Handbook before upload.

## Notes

The local release builder excludes this entire folder through `.distignore`.
