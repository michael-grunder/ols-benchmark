## OpenLiteSpeed PHP Benchmark Stack

This Docker Compose workspace stands up a single OpenLiteSpeed 1.7 + PHP 8.3 (LSAPI) target on Rocky Linux 9, paired with MariaDB and Redis. The PHP image includes APCu, igbinary, Zstandard, Redis (built with igbinary/Zstd support), and the proprietary Relay cache extension to mirror production-style workloads. Neovim ships inside the container to simplify in-container debugging.

### Prerequisites

- Docker Engine 20.10+ and Docker Compose v2.
- Internet access for `dnf` during the first build (OpenLiteSpeed, LSAPI, Relay packages, and PECL modules are downloaded at build time).

### Quick start

1. Review `.env` and adjust LSAPI knobs or database credentials for your benchmarks.
2. Place your PHP application under `www/` (mounted into the container at `/var/www/html`).
3. Build and launch:

   ```sh
   docker compose up --build
   ```

   - OpenLiteSpeed serves HTTP on `http://localhost:8080`.
   - The OpenLiteSpeed admin console is available on `https://localhost:7080` (default credentials: `admin` / `123456`; change them inside the container).

### LSAPI configuration workflow

- `.env` controls the worker pool defaults. The following variables are read at container start and rendered into `/etc/lsapi.env`:
  - `PHP_LSAPI_CHILDREN`
  - `LSAPI_MAX_REQUESTS`
  - `LSAPI_MAX_PROCESS_TIME`
  - `LSAPI_PGRP_MAX_IDLE`
  - `LSAPI_AVOID_FORK`
- Tweak those values in `.env` to experiment with different LSAPI queueing and process models. Restart the stack after changes so the entrypoint re-generates the file.
- Need per-run overrides? Set them directly under the `ols.environment` section of `docker-compose.yml`; Compose-level settings win over the `.env` defaults.
- Inspect the resolved configuration from inside the running container:

  ```sh
  docker compose exec ols cat /etc/lsapi.env
  ```

### Services

- `ols`: OpenLiteSpeed + PHP 8.3 LSAPI. Binds ports `8080` (public HTTP) and `7080` (admin UI). Mounts `./www` for application code, `ols-conf` for OpenLiteSpeed configuration persistence, and `ols-shared-tmp` for LSWS temporary files.
- `mariadb`: MariaDB 11.4 tuned for functional benchmarking. Credentials come from `.env`, and data persists via the `mariadb-data` volume.
- `redis`: Custom Redis build with igbinary/Zstd enabled, exposed on `6379` for cache-oriented tests.

### PHP toolchain highlights

- Base image: `rockylinux:9` with OpenLiteSpeed from the official LiteSpeed repository.
- PHP runtime: `lsphp83` (LSAPI) with extensions commonly needed for WordPress and synthetic benchmarks (curl, gd, intl, mysqli/mysqlnd, opcache, soap, xml, zip, etc.).
- PECL modules compiled at build time:
  - `apcu`
  - `igbinary`
  - `zstd`
  - `redis` (compiled with igbinary + Zstd)
- Relay extension: installed from the Relay EL9 repository (`relay-php83` package); adjust the repo definition in `docker/openlitespeed/Dockerfile` if the upstream URL changes.
- Tooling: Neovim is included to make container-side editing and inspection easier.

### Extending the stack

- Drop additional PHP configuration snippets into `/usr/local/lsws/lsphp83/etc/php.d/` via Dockerfile edits or bind mounts.
- Need other PECL modules or system packages? Extend the `RUN` block in `docker/openlitespeed/Dockerfile` following the existing pattern.
- Add benchmarks or fixtures under `www/`; the entrypoint seeds a simple `index.php` only when the directory is empty.

### Useful commands

- Rebuild after changing PHP extensions or the Dockerfile:

  ```sh
  docker compose build
  ```

- Tail the OpenLiteSpeed error log:

  ```sh
  docker compose exec ols tail -n 50 /usr/local/lsws/logs/error.log
  ```

- Open a shell in the application container:

  ```sh
  docker compose exec ols bash
  ```

### Notes & caveats

- OpenLiteSpeed stores its configuration under `/usr/local/lsws/conf`. The named volume `ols-conf` keeps UI changes between restarts.
- MariaDB credentials are shipped for convenience; rotate them in `.env` before running benchmarks that leave the stack exposed.
- The first build pulls packages from upstream repositories; subsequent `docker compose up` invocations will re-use the cached layers unless you change the Dockerfiles.
