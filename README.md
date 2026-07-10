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

| Board | MCU | Marlin | Klipper |
|---|---|---|---|
| BigTreeTech Manta M5P V1.0 (G0B1) | STM32G0B1RE | yes | yes |
| BigTreeTech Manta M8P V2.0 | STM32H723ZE | yes | yes |
| BigTreeTech Octopus Pro V1.1 (H723) | STM32H723ZE | yes | yes |
| BigTreeTech Octopus V1.1 | STM32F446ZE | yes | yes |
| BigTreeTech SKR 1.4 (LPC1768) | LPC1768 | yes | planned |
| BigTreeTech SKR 1.4 Turbo (LPC1769) | LPC1769 | yes | planned |
| BigTreeTech SKR 2.0 (F407) | STM32F407VG | yes | yes |
| BigTreeTech SKR 3 | STM32H743VI, STM32H723VG | yes | yes |
| BigTreeTech SKR 3 EZ | STM32H743VI, STM32H723VG | yes | yes |
| BigTreeTech SKR Mini E3 V2.0 (F103) | STM32F103RC | yes | yes |
| BigTreeTech SKR Mini E3 V3.0 | STM32G0B1RE | yes | yes |
| BigTreeTech SKR Pico V1.0 (RP2040) | RP2040 | yes | planned |
| Creality V4.2.2 (F103) | STM32F103RE | yes | yes |
| Creality V4.2.7 (F103) | STM32F103RE | yes | yes |
| FYSETC Spider (F446) | STM32F446VE | yes | yes |
| MKS Robin Nano V3 (F407) | STM32F407VG | yes | yes |
| RAMPS 1.4 (Arduino Mega / ATmega2560) | ATmega2560 | yes | planned |

Boards are JSON under `webroot/boards/` — adding one is data, not code. 17 boards ship in-box across BigTreeTech, Creality, FYSETC, Makerbase, and generic RAMPS.

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
