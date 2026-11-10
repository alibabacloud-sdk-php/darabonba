
![](https://aliyunsdk-pages.alicdn.com/icons/AlibabaCloud.svg)

## Alibaba Cloud Darabonba for PHP

[![CI](https://github.com/alibabacloud-sdk-php/darabonba/actions/workflows/ci.yml/badge.svg)](https://github.com/alibabacloud-sdk-php/darabonba/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/alibabacloud-sdk-php/darabonba/branch/master/graph/badge.svg)](https://codecov.io/gh/alibabacloud-sdk-php/darabonba)
[![Latest Stable Version](https://poser.pugx.org/alibabacloud/darabonba/v/stable)](https://packagist.org/packages/alibabacloud/darabonba)
[![License](https://poser.pugx.org/alibabacloud/tea/license)](https://packagist.org/packages/alibabacloud/darabonba)

## Installation

```sh
composer require alibabacloud/darabonba --optimize-autoloader
```

> Some users may not be able to install due to network problems, you can try to switch the Composer mirror.

## PHP compatibility

PHP 8.3, 8.4 and 8.5 are tested with current WebSocket dependencies. When
upgrading to PHP 8.4 or newer, use `ratchet/pawl` 0.4.3+ and `ratchet/rfc6455`
0.4.1+ to avoid implicit-nullability deprecations in the WebSocket dependencies.
Update both packages in the application's Composer lock file with
`composer update ratchet/pawl ratchet/rfc6455 --with-all-dependencies`.
On PHP 8.5, also require React Promise 3.x with
`composer require "react/promise:^3.0" --with-all-dependencies`.
React Promise 2.x can emit a deprecation when Composer loads it, even in
applications that only use HTTP.
Older PHP versions retain the existing dependency matrix.

The runtime no longer requires the abandoned `clue/block-react` package.
WebSocket handshakes still use their own event loop, a deadline and cancellation
of timed-out connections; the process-wide React event loop is not replaced.

## Changelog

Detailed changes for each release are documented in the [release notes](CHANGELOG.md).

## License

[Apache-2.0](LICENSE.md)

Copyright (c) 2009-present, Alibaba Cloud All rights reserved.
