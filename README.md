MediaWiki Phan Utils
===============================

This repo contains some utilities that can be used when developing [Phan] plugins.
This code was originally written as part of MediaWiki's [taint-check].

### Install

    $ `composer require mediawiki/phan-utils`

### Usage
Add `use MediaWikiPhanUtils\MediaWikiPhanUtils` to the visitor class. Note that the implementing class
MUST have the following properties:
```
/**
 * @property \Phan\Language\Context $context
 * @property \Phan\CodeBase $code_base
 */
```

Additionally, the class SHOULD implement the following method:

```
protected function getLogChannel() : string
```

to specify the name of the channel used in debug logs.

### Environment variables

You can use the `PHAN_DEBUG` variable to print debug information. The variable can take
the name of a file (if running from shell, /dev/stderr is convenient), or `-` for stdout.

License
-------

[GNU General Public License, version 2 or later]

[taint-check]: https://github.com/wikimedia/phan-taint-check-plugin
[Phan]: https://github.com/phan/phan
[GNU General Public License, version 2 or later]: COPYING
