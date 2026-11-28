# Contributing to Auto SEO Manager

**Developed by [Nyuchi Web Services](https://nyuchi.com)**  
**Lead Developer**: Bryan Fawcett ([@bryanfawcett](https://github.com/bryanfawcett))

Thank you for your interest in contributing to Auto SEO Manager! This document provides guidelines and information for contributors.

## 🤝 How to Contribute

We welcome contributions in many forms:

- 🐛 **Bug reports and fixes**
- ✨ **New features and enhancements**
- 📖 **Documentation improvements**
- 🧪 **Testing and quality assurance**
- 🌐 **Translations**
- 💡 **Ideas and feature requests**

## 🚀 Getting Started

### Prerequisites

- **WordPress** 5.0+ development environment
- **PHP** 8.2+ with WordPress coding standards
- **Git** for version control
- **Composer** for PHP dependencies
- **Node.js** for development tools

### Development Setup

1. **Fork the repository** on GitHub
2. **Clone your fork** locally:

   ```bash
   git clone https://github.com/yourusername/auto-seo-manager.git
   cd auto-seo-manager
   ```

3. **Install dependencies**:

   ```bash
   composer install
   npm install
   ```

4. **Set up WordPress** development environment:

   ```bash
   # Using Local by Flywheel, XAMPP, or Docker
   # Copy plugin to wp-content/plugins/auto-seo-manager/
   ```

5. **Create a branch** for your work:

   ```bash
   git checkout -b feature/your-feature-name
   # or
   git checkout -b fix/issue-number-description
   ```

### Development Workflow

```bash
# Make your changes
# Test your changes thoroughly

# Run code standards check
composer run cs

# Fix coding standards issues
composer run cbf

# Run tests
composer run test

# Commit your changes
git add .
git commit -m "feat: Add amazing new feature"

# Push to your fork
git push origin feature/your-feature-name

# Create Pull Request on GitHub
```

## 📝 Coding Standards

### WordPress Coding Standards

We follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/):

- **PHP**: WordPress PHP Coding Standards
- **HTML**: WordPress HTML Coding Standards
- **CSS**: WordPress CSS Coding Standards
- **JavaScript**: WordPress JavaScript Coding Standards

### Code Style Guidelines

**PHP Code:**

```php
<?php
/**
 * Function description
 *
 * @param string $param Description of parameter
 * @return bool Description of return value
 */
function auto_seo_example_function( $param ) {
    // Use tabs for indentation
    if ( ! empty( $param ) ) {
        return true;
    }

    return false;
}
```

**Naming Conventions:**

- Functions: `auto_seo_function_name()`
- Classes: `AutoSEOClassName`
- Variables: `$variable_name`
- Constants: `AUTO_SEO_CONSTANT_NAME`

**Documentation:**

- All functions must have PHPDoc comments
- Include `@param` and `@return` tags
- Explain complex logic with inline comments
- Update README.md for new features

## 🧪 Testing

### Running Tests

The suite runs against a real WordPress on SQLite, with no MySQL server. See
[TESTING.md](TESTING.md) for details.

```bash
composer install
composer run test:install-wp   # once: WordPress + SQLite drop-in
composer run test

# One file
./vendor/bin/phpunit tests/integration/UpdaterTest.php
```

### Writing Tests

Create tests for all new functionality:

```php
<?php
class TestNewFeature extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        // Setup test environment
    }

    public function test_new_feature_works() {
        // Arrange
        $input = 'test data';

        // Act
        $result = auto_seo_new_feature( $input );

        // Assert
        $this->assertEquals( 'expected result', $result );
    }
}
```

### Manual Testing Checklist

Before submitting:

- [ ] Test on fresh WordPress installation
- [ ] Test with Yoast SEO active
- [ ] Test with common plugin combinations
- [ ] Verify meta tags output in browser source
- [ ] Test admin interface functionality
- [ ] Check for PHP errors/warnings
- [ ] Test with different post types
- [ ] Verify social media sharing works

## 🐛 Bug Reports

### Before Reporting

1. **Search existing issues** to avoid duplicates
2. **Test with default theme** and only essential plugins
3. **Check plugin compatibility** list
4. **Gather system information**

### Bug Report Template

```markdown
## Bug Description
Brief description of the issue.

## Environment
- WordPress version:
- PHP version:
- Auto SEO Manager version:
- Yoast SEO version:
- Active theme:
- Other SEO plugins:

## Steps to Reproduce
1. Go to...
2. Click on...
3. See error...

## Expected Behavior
What you expected to happen.

## Actual Behavior
What actually happened.

## Screenshots/Logs
Attach relevant screenshots or log entries.
```

## ✨ Feature Requests

### Suggesting Features

1. **Check the roadmap** for planned features
2. **Search existing requests** to avoid duplicates
3. **Explain the use case** clearly
4. **Provide examples** of expected behavior
5. **Consider implementation complexity**

### Feature Request Template

```markdown
## Feature Description
Clear description of the proposed feature.

## Use Case
Why is this feature needed? What problem does it solve?

## Proposed Solution
How do you envision this working?

## Alternative Solutions
Any alternative approaches you've considered?

## Implementation Notes
Technical details or considerations (if any).
```

## 📖 Documentation

### Documentation Standards

- **Clear and concise** language
- **Step-by-step instructions** with examples
- **Screenshots** for UI elements
- **Code examples** for developers
- **Keep up-to-date** with code changes

### Documentation Structure

```
docs/
├── installation.md      # Installation guide
├── configuration.md     # Configuration options
├── integrations.md      # Plugin integrations
├── troubleshooting.md   # Common issues
├── api-reference.md     # Developer API
└── examples/            # Code examples
```

## 🌐 Translations

### Adding Translations

1. **Check existing translations** in `/languages/`
2. **Use translation tools** like Poedit or Loco Translate
3. **Follow WordPress i18n** guidelines
4. **Test translations** in your language

### Translation Guidelines

- **Use context** for ambiguous strings
- **Keep consistent** terminology
- **Test UI layout** with longer translations
- **Include cultural considerations**

## 🔄 Pull Request Process

### Before Submitting

- [ ] **Fork and branch** from `develop`
- [ ] **Follow coding standards**
- [ ] **Write or update tests**
- [ ] **Update documentation**
- [ ] **Test thoroughly**
- [ ] **Squash commits** if necessary

### Pull Request Template

```markdown
## Description
Brief description of changes.

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] Tested locally
- [ ] Added unit tests
- [ ] All tests pass

## Checklist
- [ ] Code follows standards
- [ ] Self-reviewed code
- [ ] Updated documentation
- [ ] No new warnings
```

### Review Process

1. **Automated checks** run on all PRs
2. **Code review** by maintainers
3. **Testing** in development environment
4. **Feedback and iterations**
5. **Merge** when approved

## 🏷️ Release Process

### Versioning

Every release follows the org policy
([#80](https://github.com/nyuchi/.github/issues/80)):

- **Trunk-based, `main` only.** There is no `staging` branch.
- **A merge to `main` titled `fix:`, `chore:`, `docs:` or `ci:` is a
  PATCH** (x.y.z → x.y.z+1). It is tagged automatically.
- **A merge to `main` titled `feat:` is a MINOR** (x.y.z → x.y+1.0).
- **A MAJOR is manual only**: the owner runs the release workflow with
  `bump: major`. Automation never makes one.
- Minor and patch have no upper limit: patch 999 is followed by patch
  1000, minor 999 by minor 1000. Only a major is manual.
- `workflow_dispatch` on a release workflow takes
  `bump: patch|minor|major` as a manual override.

There are no version labels. Released versions are never renumbered.
The calculator is
[`.github/actions/next-version`](./.github/actions/next-version).

---

Thank you for contributing to Auto SEO Manager! Your efforts help make WordPress SEO better for everyone. 🚀
