# SanIE production release

Create a filtered, root-domain release in a new directory outside this source tree:

```text
php deployment/build-production-release.php <new-target-directory>
```

The command refuses to overwrite an existing directory. The generated directory is the production Apache document root and serves the frontend at `/`, the API at `/backend/api/`, and the service worker with `/` scope.

The release includes only the frontend runtime, required backend runtime areas, protected avatar uploads, Composer runtime dependencies, and production `.htaccess` rules. It excludes the local `.env`, Git metadata, agents, tests, diagnostics, migrations and fixtures, backups, storage logs, SQL dumps, reset tools, development documentation, audit reports, and Composer metadata.

Create the real production `.env` separately from `.env.production.example`. Never upload the local development `.env`.
