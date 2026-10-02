# WordPress.org Directory Assets

This repository folder contains WordPress.org directory artwork and its editable vector masters. It is excluded from the release ZIP. After the plugin is approved and the screenshot refresh is complete, copy the PNG/JPEG assets from this folder to the top-level `/assets` directory of the assigned WordPress.org SVN repository, not to `/trunk/assets`. Do not upload the `source/` directory or this README.

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
- `screenshot-1.jpg` - Plugin settings page
- `screenshot-2.jpg` - Product page with Reserve button and stopwatch icon
- `screenshot-3.jpg` - Reservation modal (current layout capture; final contrast-style capture pending)
- `screenshot-4.jpg` - My Account reservations page with active and pending records
- `screenshot-5.jpg` - Admin reservations dashboard
- `screenshot-6.jpg` - Basic reservation analytics

### Refresh Status

Screenshots 1, 2, 4, 5 and 6 were captured directly from the current Free development files on 2026-10-02 and visually reviewed. They show only temporary demonstration products and reservations, not real customer data. No interface elements were recreated or edited into the images. The demo records were removed and the original test-site settings restored after capture.

Screenshot 3 was refreshed from the actual modal on 2026-10-02, including the current stopwatch Reserve button in the product background. Modal opening, Tab/Shift+Tab wrapping, Escape closing, opener-focus restoration, active success and duplicate error display were checked through the browser. The capture then exposed insufficient contrast on the modal submit button. Its default/hover colors and keyboard outlines have been corrected with automated regression coverage, but the image must be retaken to show those final styles.

Browser input stalled again at My Account's native cancellation confirmation. A fresh tab rendered but ignored clicks, so pending-approval submission, native-confirmation admin/customer actions and the final styled-modal capture remain unverified. The complete six-image set is **not yet ready for publication**, and these captures do not constitute final interaction/accessibility sign-off or an exact-release-ZIP test.

All screenshots use their actual JPEG format and `.jpg` extension. The previous modal reference was also JPEG data despite its `.png` filename; its extension was corrected without changing its pixels. WordPress.org supports both `.jpg` and `.png` screenshot names; keep exactly one file per number. See the [official directory asset guidance](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/#screenshots).

### Current Gallery

#### 1. Settings
![Reservation settings, branded header and navigation tabs](screenshot-1.jpg)

#### 2. Product Action
![Product page with the stopwatch Reserve button beside Add to cart](screenshot-2.jpg)

#### 3. Reservation Dialog
![Reservation dialog and the current stopwatch action in its product background](screenshot-3.jpg)

Layout reference captured before the final button-contrast correction. Retake before publication.

#### 4. Customer Reservations
![My Account table showing active and pending reservations](screenshot-4.jpg)

#### 5. Reservation Management
![Admin reservation filters, statuses and approval actions](screenshot-5.jpg)

#### 6. Analytics
![Reservation summary cards and recent activity](screenshot-6.jpg)

## Design Guidelines

The artwork uses the established blue, navy, and amber palette. Icons contain no small text and remain identifiable at 128 pixels. Banners keep essential content in the central safe area. Screenshots show the current Free plugin on a local test site using demonstration records. Recheck all screenshots against the final release candidate before publication.

Editable masters are stored in `source/icon.svg` and `source/banner.svg`. Re-render both required dimensions after changing a master and visually inspect the standard and high-resolution outputs before publication.

## Upload Instructions

When submitting to WordPress.org:
1. Do not include these assets in the plugin ZIP.
2. Upload them to the WordPress.org SVN repository's top-level `/assets` directory.
3. Keep screenshot numbering identical to the captions in `readme.txt`.
4. Confirm image dimensions and file-size limits against the current Plugin Handbook before upload.
5. Set the SVN MIME type to `image/jpeg` for `.jpg` assets and `image/png` for `.png` assets.

## Notes

The local release builder excludes this entire folder through `.distignore`.
