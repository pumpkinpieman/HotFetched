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

| Board | MCU | Marlin | Klipper | Min Marlin |
|---|---|---|---|---|
| BigTreeTech Kraken V1.0 (H723) | STM32H723ZG | yes | pending cfg | 2.1.3 |
| BigTreeTech Manta M5P V1.0 (G0B1) | STM32G0B1RE | yes | yes | 2.1.2 |
| BigTreeTech Manta M8P V2.0 | STM32H723ZE | yes | yes | 2.1.2 |
| BigTreeTech Octopus Pro V1.1 (H723) | STM32H723ZE | yes | yes | 2.1.2 |
| BigTreeTech Octopus V1.1 | STM32F446ZE | yes | yes | 2.0.9.4 |
| BigTreeTech SKR 1.4 (LPC1768) | LPC1768 | yes | yes | 2.0 |
| BigTreeTech SKR 1.4 Turbo (LPC1769) | LPC1769 | yes | yes | 2.0 |
| BigTreeTech SKR 2.0 (F407) | STM32F407VG | yes | yes | 2.0.9.3 |
| BigTreeTech SKR 3 | STM32H743VI, STM32H723VG | yes | yes | 2.1.2 |
| BigTreeTech SKR 3 EZ | STM32H743VI, STM32H723VG | yes | yes | 2.1.2 |
| BigTreeTech SKR Mini E3 V2.0 (F103) | STM32F103RC | yes | yes | 2.0.7 |
| BigTreeTech SKR Mini E3 V3.0 | STM32G0B1RE | yes | yes | 2.1 |
| BigTreeTech SKR Pico V1.0 (RP2040) | RP2040 | yes | yes | 2.1 |
| Creality V4.2.2 (F103) | STM32F103RE | yes | yes | 2.0.8 |
| Creality V4.2.7 (F103) | STM32F103RE | yes | yes | 2.0.8 |
| FYSETC S6 V2.0 (F446) | STM32F446VE | yes | yes | 2.1 |
| FYSETC Spider (F446) | STM32F446VE | yes | yes | 2.0.9.2 |
| RAMPS 1.4 (Arduino Mega / ATmega2560) | ATmega2560 | yes | yes | 1.1.9 |
| LDO Leviathan V1.2 (Voron, F446) | STM32F446 | — | yes | — |
| MKS Monster8 V2 (F407) | STM32F407VE | yes | yes | 2.1 |
| MKS Robin Nano V3 (F407) | STM32F407VG | yes | yes | 2.0.9.3 |
| MKS Robin Nano V3.1 (F407) | STM32F407VE | yes | yes | 2.1 |
| MKS SGEN_L V2 (LPC1769) | LPC1769 | yes | yes | 2.0.8 |
| Einsy Rambo (Prusa MK3 class, ATmega2560) | ATmega2560 | yes | yes | 2.0 |

Anything under 100 shows exactly which gate failed and why.

## Support

HotFetched is free and self-hosted. If it saved you time or a bricked board, you can support development here:

[![Ko-fi](https://img.shields.io/badge/Ko--fi-Support%20HotFetched-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/bloodthirstycheeseburger90415)

**https://ko-fi.com/bloodthirstycheeseburger90415**

## License

MIT. Sound library: [ldrolez/free-midi-chords](https://github.com/ldrolez/free-midi-chords) (MIT).
