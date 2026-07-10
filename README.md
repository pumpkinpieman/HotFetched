# HotFetched

Self-hosted 3D printer firmware workshop in a single Docker container. Pick a
motherboard, import Marlin or Klipper source, edit the configuration through a
web UI, and run confidence-gated builds where **100% means the firmware
actually compiled**.

## Features

**Marlin** — full configuration editor over `Configuration.h` and
`Configuration_adv.h` (~50 curated fields: machine, drivers, extruders,
geometry, motion/steps/accel, kinematics incl. CoreXY, endstops, TMC currents,
sensorless homing, thermal with per-thermistor limits + explicit override,
displays, probes, boot image, sounds), surgical writeback that preserves your
diff against upstream, staged build gates, real PlatformIO compile, and
`firmware.bin` + config-bundle export.

**Klipper** — board-seeded MCU firmware build (`make olddefconfig && make`)
producing `klipper.bin`, plus a generated `printer.cfg` from the board's
reference config with your geometry, speeds, and TMC currents applied.

**Sound designer** — power-on tune baked into Marlin firmware, per-event M300
host G-code, browser piezo preview, RTTTL/MIDI/audio melody import, format
export (.mid/.rtttl/.txt), and a searchable 11,000-melody library
(ldrolez/free-midi-chords, MIT).

**Boot images** — any PNG/JPEG dithered to a 128x64 1-bit `_Bootscreen.h`
with live preview.

## Supported boards

| Board | Marlin | Klipper |
|---|---|---|
| BigTreeTech SKR 3 (H743/H723) | yes | yes |
| BigTreeTech SKR 3 EZ (H743/H723) | yes | yes |
| BigTreeTech SKR Mini E3 V3.0 | yes | yes |
| BigTreeTech Octopus V1.1 (F446) | yes | yes |
| BigTreeTech Manta M8P V2.0 | yes | yes |

Boards are JSON under `webroot/boards/` — adding one is data, not code.

## Run

```bash
docker run -d --name HotFetched \
  -p 16356:80 \
  -v /path/to/appdata/hotfetched/private:/var/www/html/private \
  -v /path/to/appdata/hotfetched/platformio:/opt/platformio \
  --restart unless-stopped \
  ghcr.io/pumpkinpieman/hotfetched:latest
```

**Unraid:** template at `deploy/hotfetched.xml`. Keep the private path on
cache/pool storage (SQLite and FUSE user shares do not mix). The platformio
volume caches the ~1-2 GB STM32 toolchain across updates.

## Build gates

| Stage | Points | What it proves |
|---|---|---|
| Static validation | 40 | fields present, within board limits, valid selections, no conflicts |
| Config integrity | 20 | files parse, MOTHERBOARD matches the board |
| Real compile | 40 | PlatformIO / make exits 0 and the binary exists |

Anything under 100 shows exactly which gate failed and why.

## License

MIT. Sound library: [ldrolez/free-midi-chords](https://github.com/ldrolez/free-midi-chords) (MIT).
