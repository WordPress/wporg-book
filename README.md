WordPress Book
===============

## Development

### Prerequisites

* Docker
* Node/npm
* Composer

### Setup

1. Set up repo dependencies.

    ```bash
    npm install
    composer install
    TEXTDOMAIN=wporg-book composer exec update-configs
    ```

1. Build the theme.

    ```bash
    npm run build
    ```

1. Start the local environment.

    ```bash
    npm wp-env start
    ```

1. Visit site at [localhost:8888](http://localhost:8888).

1. Log in with username `admin` and password `password`.

## Chapter import

Chapters are imported from the Markdown in [WordPress/library](https://github.com/WordPress/library). Its `manifest.json` maps each chapter file to the slug of an existing post here, and `inc/import-chapters.php` keeps those posts' content and titles in step with the repo. It never creates or deletes posts, and it leaves dates, slugs and the `mb_*` navigation meta alone.

It needs the `wporg-markdown` plugin from the Meta repo and Jetpack's Markdown library on the site. The scheduled import stays off until it's turned on:

```bash
wp book import --dry-run                       # lists each chapter and whether it would change
wp book import                                 # imports once
wp option update wporg_book_import_enabled 1   # then imports hourly
```

`wp book import --force` converts every chapter again, which is needed after changing the conversion code (or bump `Chapter_Importer::TRANSFORM_VERSION`).

To try it locally, add both plugins in `.wp-env.override.json`, pointing `wporg-markdown` at a checkout of [WordPress/wordpress.org](https://github.com/WordPress/wordpress.org). To import from a branch of the library, filter `wporg_book_manifest_url`.
