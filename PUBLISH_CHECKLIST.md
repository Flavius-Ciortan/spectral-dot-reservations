# Spectral Dot Reservations Release Checklist

This checklist describes release gates, not completed test results. Record evidence for the exact candidate commit and ZIP. Source changes invalidate the corresponding candidate evidence.

## Identity and Documentation

- [ ] Display name is Spectral Dot - Product Reservations for WooCommerce.
- [ ] Directory, bootstrap and text domain use spectral-dot-reservations.
- [ ] Author display is Spectral Dot; any contact links are verified and intentional.
- [ ] Header, SDPR_VERSION and readme stable tag agree on 1.0.0 for the initial public release.
- [ ] GPL-3.0-or-later license and required third-party attribution are intact.
- [ ] Public code, metadata, assets and translation catalog use the current identity exclusively.
- [ ] Readme scope accurately describes logged-in customers, simple stock-managed products and one unit per reservation.
- [ ] Directory artwork and six screenshots match the current UI and readme captions.

## Verification

- [ ] PHP/JavaScript/shell syntax and WordPress coding standards pass.
- [ ] Integration, scoped/dismissible notice, concurrency and HPOS checks pass.
- [ ] Supported minimum and current stacks pass; compatibility metadata is evidence-based.
- [ ] Browser tests cover frontend/admin roles, settings validation, notices, mobile, keyboard operation, and classic/block checkout.
- [ ] Plugin Check passes or residual non-blocking advisories have a specific reviewed rationale.
- [ ] Activation, deactivation, reactivation and uninstall pass on a disposable site.
- [ ] Privacy exporter/eraser, inventory ownership, cron and mail-event counts pass.
- [ ] CI is passing for the exact release commit, not just an earlier revision.

## Package

- [ ] Build from a clean committed tree with bin/build-release.sh.
- [ ] ZIP has one spectral-dot-reservations directory and spectral-dot-reservations.php bootstrap.
- [ ] No development tools, local plans, private data, credentials or paid plugin files are packaged.
- [ ] SHA-256 verifies and a second build is byte-for-byte identical.
- [ ] Install and test the actual ZIP on a clean disposable environment.
- [ ] Preserve the tested ZIP, checksum, commit, environment versions and evidence together.

## Review and Publication

- [ ] Confirm ownership of the existing pending submission before uploading a revision.
- [ ] Obtain final approval for the exact tested candidate and form details.
- [ ] Upload through the existing submission; do not create a duplicate submission.
- [ ] Reply briefly in the existing review thread and explicitly request spectral-dot-reservations.
- [ ] Record successful receipt. Upload is not directory approval.
- [ ] After approval, publish verified trunk/tag and directory assets using the assigned SVN repository.

Directory icons/banners/screenshots in .wordpress-org belong in SVN assets after approval and are excluded from the runtime ZIP. Automatic publication is not triggered merely by pushing source changes.
