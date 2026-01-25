# Claude Guidance for Drupal Projects
You are within a DDEV local development environment.

## Build/Lint/Test Commands
- **Build**: `composer install`
- **Install**: `drush site:install --existing-config`
- **Lint**:
  - If the project has a `/phpcs.xml` or `/phpcs.xml.dist`: `phpcs`
  - Otherwise: `phpcs --standard=Drupal path/to/test`
- **Static Analysis**:
  - If the project has a `/phpstan.neon` or `phpstan.neon.dist`: `phpstan`
  - Otherwise: `phpstan analyse --level 6 path/to/test`
- **Run Single Test**:
  - If the project has a `/phpunit.xml` or `/phpunit.xml.dist`: `phpunit --filter Test path/to/test`
  - Otherwise: `phpunit -c [web-root]/core/phpunit.xml.dist --filter Test path/to/test`

## Configuration Management
- **Export configuration**: `drush config:export -y`
- **Import configuration**: `drush config:import -y`
- **Import partial configuration**: E.g. to reset to a module's install config `drush config:import --partial --source=[path-to-module/config/install`
- **Verify configuration**: `drush config:export --diff`
- **View config details**: `drush config:get [config.name]`
- **Change config value**: `drush config:set [config.name] [key] [value]`
- **Install from config**: `drush site:install --existing-config`
- **Get the config sync directory**: `tell  drush status --field=config-sync`

## Development Commands
- **List available modules**: `drush pm:list [--filter=FILTER]`
- **List enabled modules**: `drush pm:list --status=enabled [--filter=FILTER]`
- **Download a Drupal module**: `composer require drupal/[module_name]`
- **Install a Drupal module**: `drush en [module_name]`
- **Clear cache**: `drush cache:rebuild`
- **Inspect logs**: `drush watchdog:show --count=20`
- **Delete logs**: `drush watchdog:delete all`
- **Run cron**: `drush cron`
- **Show status**: `drush status`

## Entity Management
- **View fields on entity**: `drush field:info [entity_type] [bundle]`

## Best Practices
- If making configuration changes to a module's config/install, these should also be applied to active configuration
- Always export configuration after making changes
- Check configuration diffs before importing
- If a module provides install configuration, this should be done via `config/install` not `hook_install`
- Attempt to use contrib modules for functionality, rather than replicating in a custom module
- If phpcs/phpstan/phpunit are not available, they should be installed by `composer require --dev drupal/core-dev`

## Code Style Guidelines
- **PHP Version**: 8.3+ compatibility required
- **Coding Standard**: Drupal coding standards
- **Indentation**: 2 spaces, no tabs
- **Line Length**: 120 characters maximum
- **Comment**: 80 characters maximum line length, always finishing with a full stop
- **Namespaces**: PSR-4 standard, `Drupal\{module_name}`
- **Types**: Strict typing with PHP 8 features, union types when needed
- **Documentation**: Required for classes and methods with PHPDoc
- **Class Structure**: Properties before methods, dependency injection via constructor
- **Naming**: CamelCase for classes/methods/properties, snake_case for variables, ALL_CAPS for constants
- **Error Handling**: Specific exception types with `@throws` annotations, meaningful messages
- **Plugins**: Follow Drupal plugin conventions with attributes for definition

When working in this codebase, prioritize adherence to Drupal patterns and conventions.
