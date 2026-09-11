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
- **PHP** 7.4+ with WordPress coding standards
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

```bash
# Run all tests
composer run test

# Run specific test file
./vendor/bin/phpunit tests/TestAutoSEOManager.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/
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

### Version Numbering

We use [Semantic Versioning](https://semver.org/):

- **MAJOR** (1.0.0): Breaking changes
- **MINOR** (1.1.0): New features, backwards compatible
- **PATCH** (1.0.1): Bug fixes

### Release Checklist

- [ ] Update version numbers
- [ ] Update CHANGELOG.md
- [ ] Test compatibility
- [ ] Update documentation
- [ ] Create GitHub release
- [ ] Submit to WordPress.org (if applicable)

## 🏛️ Governance

### Maintainers

Current maintainers have the authority to:

- Review and merge pull requests
- Create releases
- Moderate discussions
- Set project direction

### Decision Making

- **Small changes**: Any maintainer can approve
- **Major changes**: Require discussion and consensus
- **Breaking changes**: Require community input

### Code of Conduct

We follow the [WordPress Community Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/):

- **Be respectful** and inclusive
- **Assume good intentions**
- **Give constructive feedback**
- **Focus on what's best** for the community

## 📞 Getting Help

### Communication Channels

- **GitHub Issues**: Bug reports and feature requests
- **GitHub Discussions**: General questions and ideas
- **Professional Support**: [Nyuchi Web Services](https://nyuchi.com)
- **Developer Contact**: Bryan Fawcett [@bryanfawcett](https://github.com/bryanfawcett)

### Response Times

- **Bug reports**: Within 48 hours
- **Feature requests**: Within 1 week
- **Pull requests**: Within 1 week
- **Security issues**: Within 24 hours

## 🎯 Contribution Areas

### High Priority

Current areas where contributions are especially welcome:

- **Performance optimization**
- **Integration with new plugins**
- **Accessibility improvements**
- **Mobile responsiveness**
- **Security enhancements**

### Skill-Based Contributions

**PHP Developers:**

- Core plugin features
- Plugin integrations
- Performance optimization
- Security improvements

**Frontend Developers:**

- Admin interface improvements
- Accessibility enhancements
- Mobile optimization

**Documentation Writers:**

- User guides
- Developer documentation
- Tutorial content
- Translation

**Testers:**

- Compatibility testing
- User experience testing
- Performance testing
- Security testing

**Designers:**

- UI/UX improvements
- Plugin assets
- Marketing materials

## 🙏 Recognition

### Contributors

All contributors are recognized in:

- **GitHub contributors page**
- **Plugin credits section**
- **Release notes mentions**
- **Annual contributor highlights**

### Types of Recognition

- **Code contributions**: Listed in plugin credits
- **Documentation**: Mentioned in docs sections
- **Testing**: Acknowledged in release notes
- **Translations**: Listed in language packs

## 📚 Resources

### WordPress Development

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- [Plugin Development Handbook](https://developer.wordpress.org/plugins/)
- [WordPress Hooks Reference](https://developer.wordpress.org/reference/hooks/)

### SEO Resources

- [Google SEO Guidelines](https://developers.google.com/search/docs)
- [Schema.org Documentation](https://schema.org/)
- [Open Graph Protocol](https://ogp.me/)
- [Twitter Cards Guide](https://developer.twitter.com/en/docs/twitter-for-websites/cards/overview/abouts-cards)

### Tools

- [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards)
- [PHPUnit for WordPress](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/)
- [WP-CLI](https://wp-cli.org/)

---

Thank you for contributing to Auto SEO Manager! Your efforts help make WordPress SEO better for everyone. 🚀
