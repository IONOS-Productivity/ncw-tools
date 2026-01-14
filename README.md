# NCW Tools

Utility app providing event listeners and extensible tools for system administration and maintenance tasks.

## Description

NCW Tools enhances Nextcloud with advanced system utilities including event listeners for monitoring system events, and extensible tools designed to streamline system administration and maintenance workflows.

## Installation

### From Source

1. Clone this repository into your Nextcloud apps directory:
   ```bash
   cd nextcloud/apps
   git clone <repository-url> ncw_tools
   ```

2. Install dependencies:
   ```bash
   cd ncw_tools
   composer install
   ```

3. Enable the app in your Nextcloud admin panel or via command line:
   ```bash
   php occ app:enable ncw_tools
   ```

## Development

### Prerequisites

- PHP 8.1+
- Composer

### Setup

1. Clone the repository
2. Install dependencies:
   ```bash
   composer install
   ```

### Available Scripts

- `composer lint` - Lint PHP files
- `composer cs:check` - Check coding style
- `composer cs:fix` - Fix coding style
- `composer psalm` - Run static analysis
- `composer test:unit` - Run unit tests

### Testing

Run the unit tests:
```bash
composer test:unit
```

## License

This project is licensed under the AGPL-3.0-or-later license. See the [LICENSE](LICENSE) file for details.
