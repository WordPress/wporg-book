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
