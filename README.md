# HotFetched

> **Status: experimental / test project — not production ready.**

Self-hosted 3D printer firmware configuration and build workshop, delivered as a single Docker container.

Pick a motherboard, choose Marlin or Klipper, import the firmware source (GitHub or default ZIP), edit the key configuration values through a web UI, then run a confidence-gated build:

- **Marlin** — edits `Configuration.h` / `Configuration_adv.h`, validates values against board limits, and compiles with PlatformIO. 100% confidence = a real successful compile with `firmware.bin` produced.
- **Klipper** — builds the MCU firmware for the board and generates a validated `printer.cfg`.

## Stack

- PHP 8.3 + Apache (`php:8.3-apache`)
- SQLite (PDO)
- PlatformIO Core (Marlin builds)
- `gcc-arm-none-eabi` (Klipper MCU firmware)

## Supported boards

| Board | Marlin define | MCU variants |
|---|---|---|
| BigTreeTech SKR 3 | `BOARD_BTT_SKR_V3_0` | STM32H743VI / STM32H723VG |
| BigTreeTech SKR 3 EZ | `BOARD_BTT_SKR_V3_0_EZ` | STM32H743VI / STM32H723VG |

Boards are defined as JSON in `webroot/boards/` — adding a board is data, not code.

## Run

```bash
docker build -t hotfetched .
docker run -d --name HotFetched \
  -p 8090:80 \
  -v /path/to/appdata/hotfetched/private:/var/www/html/private \
  -v /path/to/appdata/hotfetched/platformio:/opt/platformio \
  hotfetched
```

Then open `http://<host>:8090/`.

**Unraid:** template at `deploy/hotfetched.xml`. Keep the private data path on cache-only storage (SQLite + user shares don't mix).

## Layout

```
webroot/        # DocumentRoot — UI, API, board definitions
private/        # (volume) SQLite DB, project sources, build artifacts
deploy/         # Unraid Docker template
schema.sql      # Schema documentation (app builds schema from bootstrap.php)
```

## Roadmap

- [x] Phase 1 — projects, board definitions, SQLite schema, Docker image
- [ ] Phase 2 — firmware source acquisition (GitHub clone / ZIP import, hardened)
- [ ] Phase 3 — Marlin `#define` parser + configuration editor
- [ ] Phase 4 — build worker, confidence gates, artifact export
- [ ] Phase 5 — Klipper path (menuconfig presets + `printer.cfg` generator)

## License

TBD — test project.
