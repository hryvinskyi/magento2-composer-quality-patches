# Composer Quality Patches Plugin

Automatically applies Magento quality patches during `composer install` and `composer update`.

## Features

- Automatically applies patches from `magento/quality-patches`

## Installation

```bash
composer require hryvinskyi/magento2-composer-quality-patches
```

## Configuration

Add configuration to your `composer.json` to specify which patches to install:

```json
{
    "extra": {
        "hryvinskyi-quality-patches": {
            "enabled": true,
            "patches": [
               "ACSD-52277",
               "ACSD-63326",
               "ACSD-58108"
            ]
        }
    }
}
```

### Configuration Options

- **enabled** (bool, default: `true`): Enable or disable the plugin
- **patches** (array, default: `[]`): List of patch IDs to apply

## Requirements

- PHP 8.1+
- Composer 2.0+
- `magento/quality-patches` or `magento/magento-cloud-patches` package

## License

MIT
