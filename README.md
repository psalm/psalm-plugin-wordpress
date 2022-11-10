<h1>WordPress plugin for Psalm</h1>

[![Packagist](https://img.shields.io/packagist/v/humanmade/psalm-plugin-wordpress.svg)](https://packagist.org/packages/humanmade/psalm-plugin-wordpress)
[![Packagist](https://img.shields.io/packagist/dt/humanmade/psalm-plugin-wordpress.svg)](https://packagist.org/packages/humanmade/psalm-plugin-wordpress)

Write type-safe WordPress code.

This [Psalm](https://psalm.dev/) plugin provides all WordPress and WP CLI stubs, so your WordPress based project or plugin will have type information for calls to WordPress APIs. This ensures your WordPress plugin or theme has less bugs!

- [x] Stubs for all of WordPress Core
- [x] Stubs for WP CLI
- [x] Types for `apply_filters` return values.
- [x] Types for `add_filter` / `add_action`
- [x] Configuration options to use your own stubs

## Installation

`composer require --dev humanmade/plugin-wordpress`

and add this to your psalm.xml config:

```
<plugins xmlns="https://getpsalm.org/schema/config">	
    <pluginClass class="PsalmWordPress\Plugin" />
</plugins>
```

Further details, can be found on [psalm's website](https://psalm.dev/docs/running_psalm/plugins/using_plugins/).

## Interested in contributing?

Feel free to open a PR to fix bugs or add features!

In addition, have a look at psalm's [CONTRIBUTING.md](https://github.com/vimeo/psalm/blob/master/CONTRIBUTING.md).

## Who made this

Created by @joehoyle, maintained by the Psalm community.




