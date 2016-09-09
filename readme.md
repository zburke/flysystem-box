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

To generate a temporary developer token, visit https://app.box.com/developers/services/
and click the "Edit Application" button for the app you want to use, then
click the "Create a developer token" button.


## Support

If you believe you have found a bug, please report it using the [GitHub issue tracker](https://github.com/zburke/flysystem-box/issues),
or better yet, fork the library and submit a pull request.
