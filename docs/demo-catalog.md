# Local demo catalog

This opt-in demo runs the **real Laravel API** with a separate `/tmp/liper-demo/liper-demo.sqlite` database. It does not edit `.env`, run migrations against the normal database, create administrator accounts, or publish content to the live server. `DatabaseSeeder` does not invoke it. The seeder refuses production and non-demo database paths.

## Fixtures

- Six collections: Aurora, Ocean, Prism, Ember, Fluid and Noir.
- 48 stable, titled entries, interleaved across collections: 8 per collection, both free and premium.
- 12 original procedural videos (2 per collection), reused across the 48 pagination fixtures. These are test graphics, not 48 distinct videos or production artwork.
- Each video is a silent 4-second, 540×960, 60 fps H.264 MP4 with a matching JPEG poster. FFmpeg generates the files; no external assets are downloaded.
- Three feed pages at the app's page size: 20 + 20 + 8. Re-seeding is idempotent.

## Run

Requires PHP/composer dependencies, Python 3, FFmpeg and OpenSSL. Run from the API project:

```sh
python3 tools/generate-demo-media.py /tmp/liper-demo/media
python3 tools/run-demo-api.py
```

The second command migrates/seeds only the isolated database and keeps Laravel on `127.0.0.1:8765`. In another terminal, create a short-lived **simulator-only** certificate:

```sh
mkdir -p /tmp/liper-demo/tls
openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout /tmp/liper-demo/tls/key.pem -out /tmp/liper-demo/tls/cert.pem \
  -days 7 -subj '/CN=Liper Simulator Demo' \
  -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' \
  -addext 'basicConstraints=critical,CA:TRUE' \
  -addext 'keyUsage=critical,digitalSignature,keyEncipherment,keyCertSign' \
  -addext 'extendedKeyUsage=serverAuth'
xcrun simctl keychain booted add-root-cert /tmp/liper-demo/tls/cert.pem
python3 tools/serve-demo-tls.py --media /tmp/liper-demo/media \
  --cert /tmp/liper-demo/tls/cert.pem --key /tmp/liper-demo/tls/key.pem
```

The HTTPS gateway binds only `127.0.0.1:9443`, proxies `/api/v1/*` to Laravel and serves media with byte-range support. It is a development tool, not a production web server. Keep the certificate/key outside the repository. The app retains HTTPS validation; no ATS exception or trust bypass is added.

Launch the installed **Debug** app in the simulator:

```sh
xcrun simctl launch booted com.rashed.LipersFree -hasOnboarded YES -LiperDemoCatalog YES
```

Home visibly labels this mode “DEMO CATALOG · LOCAL API”. Release builds ignore the flag. Relaunch without `-LiperDemoCatalog YES` to use the configured real API. Stop the two servers with Ctrl-C when finished. Generated media and the database remain under `/tmp/liper-demo` and can be regenerated.

## Verify

```sh
php artisan test
vendor/bin/pint --test database/seeders/DemoCatalogSeeder.php tests/Feature/DemoCatalogTest.php
python3 tools/check-demo-api.py --cert /tmp/liper-demo/tls/cert.pem
```

Backend tests cover repeatable seeding, filters, 48 unique IDs across the three cursor pages, mixed premium/free entries and the production guard. The HTTP check verifies actual category/feed responses, all posters and HTTP 206 byte ranges for all 12 videos. iOS UI tests `testDemoCatalogBrowsingAndPagination` and `testDemoVideoCreatesLivePhoto` use this API; they skip when it is unavailable. No live-server deployment is performed by these tools.

## 60 fps replacement bundle

The generator defaults to native 60 fps and halves the per-frame angular step relative to the original 30 fps fixtures, retaining their motion speed. `--fps 30` is available for controls. A manifest records file hashes. Verify before deployment:

```sh
python3 tools/generate-demo-media.py /tmp/liper-demo-60fps/media
python3 tools/verify-demo-motion.py /tmp/liper-demo-60fps/media
php artisan catalog:upgrade-demo-motion /path/to/bundle/media --dry-run
php artisan catalog:upgrade-demo-motion /path/to/bundle/media
```

The upgrade command operates on the existing v1 demo categories and 48 entries, preserves IDs and flags, and changes media URLs to `demo-catalog/v2-60fps/`. It refuses edited demo entries, checksum mismatches, and conflicting destination files. It copies files before updating rows in a database transaction, revalidates under row locks, and cleans up newly written files on pre-commit failure. It retains v1 media. Do not rerun the v1 importer on upgraded rows. Full VPS upload instructions are in the app project at `docs/VPS-60FPS-UPDATE.md`.
