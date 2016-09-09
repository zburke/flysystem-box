# Flysystem Adapter for Box.com

## Installation

```bash
composer require zburke/flysystem-box
```

## Usage

Assuming you have a valid bearer token from an oAuth2 authentication session:

```php
use Zburke\Flysystem\Box\BoxAdapter;
use League\Flysystem\Filesystem;

$adapter = new BoxAdapter('oauth-token', [$prefix]);

$filesystem = new Filesystem($adapter);
```

You ca
