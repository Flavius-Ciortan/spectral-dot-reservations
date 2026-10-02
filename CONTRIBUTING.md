# Contributing to Spectral Dot Reservations

Thank you for considering a contribution to Spectral Dot Reservations. This document provides development and review guidelines.

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Getting Started](#getting-started)
3. [How to Contribute](#how-to-contribute)
4. [Coding Standards](#coding-standards)
5. [Pull Request Process](#pull-request-process)
6. [Reporting Bugs](#reporting-bugs)
7. [Feature Requests](#feature-requests)

## Code of Conduct

This project adheres to a code of conduct. By participating, you are expected to uphold this code:

- Be respectful and inclusive
- Welcome newcomers
- Focus on what is best for the community
- Show empathy towards other contributors

## Getting Started

### Development Environment

1. **Requirements:**
   - WordPress 6.5+
   - WooCommerce 8.0+
   - PHP 7.4+
   - A database version supported by the selected WordPress release
   - Node.js for JavaScript syntax checks
   - Composer for PHP quality tooling

2. **Setup:**
   ```bash
   git clone <repository-url> spectral-dot-reservations
   cd spectral-dot-reservations
   ```

3. **Install in WordPress:**
   - Copy plugin to `wp-content/plugins/spectral-dot-reservations`
   - Activate in WordPress admin
   - Enable WooCommerce

## How to Contribute

### Types of Contributions

We welcome:

- **Bug fixes**
- **Feature enhancements**
- **Documentation improvements**
- **Translation files**
- **Code optimization**
- **Test coverage**
- **UI/UX improvements**

### Workflow

1. **Fork** the repository
2. **Create** a feature branch (`git checkout -b feature/amazing-feature`)
3. **Make** your changes
4. **Test** thoroughly
5. **Commit** your changes (`git commit -m 'Add amazing feature'`)
6. **Push** to the branch (`git push origin feature/amazing-feature`)
7. **Open** a Pull Request

## Coding Standards

### PHP

Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/):

```php
<?php
// Good
if ( $condition ) {
    do_something();
}

// Bad
if($condition){
    do_something();
}
```

### JavaScript

Follow WordPress JavaScript coding standards:

```javascript
// Good
function doSomething() {
    var value = getValue();
    return value;
}

// Bad
function doSomething(){
  var value=getValue()
  return value
}
```

### CSS

```css
/* Good */
.class-name {
    property: value;
}

/* Bad */
.className{property:value}
```

### Key Principles

1. **Security First**
   - Sanitize all inputs
   - Escape all outputs
   - Use nonces for forms
   - Check capabilities

2. **WordPress Way**
   - Use WordPress functions
   - Follow WP hooks system
   - Respect WP database structure
   - Use WP i18n functions

3. **Documentation**
   - Comment complex logic
   - Use PHPDoc blocks
   - Update user documentation
   - Add inline code comments

4. **Translation Ready**
   - Use `__()`, `_e()`, `esc_html__()`, `esc_html_e()`
   - Maintain consistent text domain: `spectral-dot-reservations`
   - Provide context where needed

## Pull Request Process

### Before Submitting

- [ ] Code follows WordPress coding standards
- [ ] All strings are translatable
- [ ] Security best practices followed
- [ ] No console.log or debug code
- [ ] Tested on multiple browsers
- [ ] Tested with latest WordPress/WooCommerce
- [ ] Documentation updated
- [ ] CHANGELOG.md updated

### PR Guidelines

1. **Title:** Clear, concise description
2. **Description:** What, why, how
3. **Issue Reference:** Link related issues
4. **Screenshots:** For UI changes
5. **Testing:** Describe test scenarios

### Example PR Description

```markdown
## What
Improves reservation expiry notices

## Why
Merchants need clearer diagnostics when scheduled expiry is delayed

## How
- Added a focused Site Health diagnostic
- Kept expiration behavior in the canonical expiration service
- Added integration coverage for schedule recovery

## Testing
- Removed the scheduled event
- Ran the health check
- Verified that the event was restored once
- Ran the integration and lifecycle suites

Fixes #123
```

## Reporting Bugs

### Before Reporting

1. **Search** existing issues
2. **Test** with default theme
3. **Disable** other plugins
4. **Check** error logs

### Bug Report Template

```markdown
**Describe the bug**
Clear and concise description.

**To Reproduce**
Steps to reproduce:
1. Go to '...'
2. Click on '...'
3. See error

**Expected behavior**
What should happen.

**Screenshots**
If applicable, add screenshots.

**Environment:**
- WordPress version:
- WooCommerce version:
- Plugin version:
- PHP version:
- Browser:
- Theme:

**Additional context**
Any other relevant information.
```

## Feature Requests

### Before Requesting

1. **Search** existing requests
2. **Check** roadmap
3. **Consider** scope

### Feature Request Template

```markdown
**Is your feature request related to a problem?**
Clear description of the problem.

**Describe the solution you would like**
Clear description of what you want to happen.

**Describe alternatives you've considered**
Other solutions you've thought about.

**Use cases**
How would this feature be used?

**Additional context**
Mockups, examples, similar plugins, etc.
```

## Development Guidelines

### File Structure

```
spectral-dot-reservations/
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
├── includes/
│   ├── admin/
│   ├── frontend/
│   └── *.php
├── templates/
│   └── myaccount/
├── languages/
├── spectral-dot-reservations.php
├── readme.txt
└── USER_GUIDE.md
```

### Naming Conventions

- **Files:** `class-sdpr-name.php`
- **Classes:** `SDPR_Class_Name`
- **Functions:** `sdpr_function_name()`
- **Hooks:** `sdpr_hook_name`
- **Database:** `_sdpr_meta_key`

### Adding Hooks

```php
// Good - documented and flexible
/**
 * Fires after reservation is created
 *
 * @param int $reservation_id The reservation ID
 * @param int $product_id The product ID
 * @param int $user_id The user ID
 */
do_action( 'sdpr_reservation_created', $reservation_id, $product_id, $user_id );
```

### Database Queries

```php
// Good - prepared statement
$wpdb->prepare(
    "SELECT * FROM $wpdb->posts WHERE ID = %d",
    $post_id
);

// Bad - SQL injection risk
$wpdb->query( "SELECT * FROM $wpdb->posts WHERE ID = $post_id" );
```

## Testing

### Manual Testing Checklist

- [ ] Install fresh WordPress
- [ ] Install WooCommerce
- [ ] Install plugin
- [ ] Activate and configure
- [ ] Create test product
- [ ] Test reservation flow
- [ ] Test expiration
- [ ] Test My Account page
- [ ] Test admin dashboard
- [ ] Test email notifications
- [ ] Test approval workflow
- [ ] Test cancellation
- [ ] Test stock restoration

### Browser Testing

Test on:
- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

### PHP Version Testing

Test on:
- PHP 7.4
- PHP 8.0
- PHP 8.1
- PHP 8.2

## Documentation

### Code Comments

```php
/**
 * Create a new product reservation
 *
 * @param int $product_id The product ID to reserve
 * @param int $user_id The user ID making the reservation
 * @return int|false Reservation ID on success, false on failure
 */
public function create_reservation( $product_id, $user_id ) {
    // Implementation
}
```

### User Documentation

Update these files:
- `readme.txt` - WordPress.org description
- `USER_GUIDE.md` - Comprehensive guide
- `CHANGELOG.md` - Version history

## Release Process

Handled by maintainers:

1. Version bump in main file
2. Update CHANGELOG.md
3. Update readme.txt
4. Create GitHub release
5. Tag version
6. Submit to WordPress.org SVN

## Questions?

- **GitHub Issues:** Technical questions
- **WordPress Forum:** User support
- **Email:** For sensitive matters

## License

By contributing, you agree that your contributions will be licensed under GPL-3.0-or-later.

---

Thank you for helping improve Spectral Dot Reservations.
