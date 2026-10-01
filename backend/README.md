# Backend

Laravel 13 app with two faces:

- the REST API at `/api/v1`, used by the Expo customer app and kitchen screens, documented
  in [../docs/API.md](../docs/API.md);
- the Filament owner back office at `/admin`.

Local development uses Laravel Sail; see [../docs/SETUP.md](../docs/SETUP.md). The short version:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail composer setup   # first run only
./vendor/bin/sail composer check   # Pint, Larastan and the Pest suite
```

Design decisions are recorded in [../docs/DECISIONS.md](../docs/DECISIONS.md).
