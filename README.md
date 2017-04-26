Team-Migrations-Common
======================

Migrations system for storage structure and database schema.

## Requirements
- PHP >= 5.5
- [symfony/console](https://github.com/symfony/Console) ~2.6|~3.0
- [symfony/event-dispatcher](https://github.com/symfony/EventDispatcher) ~2.0|~3.0
- [symfony/finder](https://github.com/symfony/Finder) ~2.0|~3.0

## Installation
    php composer.phar require moro/team-migrations-common "~1.6"

## Usage
    vendor/bin/migrations status

    vendor/bin/migrations migrate

    vendor/bin/migrations create

## License
Package __moro/team-migrations-common__ is licensed under the MIT license.

2015-2017