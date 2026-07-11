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

| Board | MCU | Marlin | Klipper | RRF |
|---|---|---|---|---|
| BigTreeTech GTR V1.0 (F407) | STM32F407 | yes | yes | yes |
| BigTreeTech Kraken V1.0 (H723) | STM32H723ZG | yes | pending cfg | yes |
| BigTreeTech Manta E3 EZ V1.0 | STM32G0B1 | yes | yes | - |
| BigTreeTech Manta M4P | STM32G0B1 | yes | yes | - |
| BigTreeTech Manta M5P V1.0 (G0B1) | STM32G0B1RE | yes | yes | - |
| BigTreeTech Manta M8P V1.1 (G0B1) | STM32G0B1 | yes | yes | - |
| BigTreeTech Manta M8P V2.0 | STM32H723ZE | yes | yes | - |
| BigTreeTech Octopus Max EZ V1.0 (H723) | STM32H723 | yes | yes | - |
| BigTreeTech Octopus Pro V1.0 (F446) | STM32F446 | yes | yes | - |
| BigTreeTech Octopus Pro V1.1 (H723) | STM32H723ZE | yes | yes | - |
| BigTreeTech Octopus V1.1 | STM32F446ZE | yes | yes | - |
| BigTreeTech SKR 1.3 (LPC1768) | LPC1768 | yes | yes | yes |
| BigTreeTech SKR 1.4 (LPC1768) | LPC1768 | yes | yes | yes |
| BigTreeTech SKR 1.4 Turbo (LPC1769) | LPC1769 | yes | yes | yes |
| BigTreeTech SKR 2.0 (F407) | STM32F407VG | yes | yes | yes |
| BigTreeTech SKR 3 | STM32H743VI, STM32H723VG | yes | yes | yes |
| BigTreeTech SKR 3 EZ | STM32H743VI, STM32H723VG | yes | yes | yes |
| BigTreeTech SKR E3 Turbo (LPC1769) | LPC1769 | yes | yes | yes |
| BigTreeTech SKR Mini E3 V1.2 (F103) | STM32F103 | yes | yes | - |
| BigTreeTech SKR Mini E3 V2.0 (F103) | STM32F103RC | yes | yes | - |
| BigTreeTech SKR Mini E3 V3.0 | STM32G0B1RE | yes | yes | - |
| BigTreeTech SKR Mini MZ V1.0 (F103) | STM32F103 | yes | yes | - |
| BigTreeTech SKR Pico V1.0 (RP2040) | RP2040 | yes | yes | - |
| BigTreeTech SKR Pro V1.2 (F407) | STM32F407 | yes | yes | yes |
| Creality V4.2.10 (F103) | STM32F103 | yes | yes | - |
| Creality V4.2.2 (F103) | STM32F103RE | yes | yes | - |
| Creality V4.2.7 (F103) | STM32F103RE | yes | yes | - |
| FYSETC Cheetah V2.0 (F401) | STM32F401 | yes | yes | - |
| FYSETC F6 V1.3 (ATmega2560) | ATmega2560 | yes | yes | - |
| FYSETC S6 V2.0 (F446) | STM32F446VE | yes | yes | - |
| FYSETC Spider (F446) | STM32F446VE | yes | yes | - |
| RAMPS 1.4 (Arduino Mega / ATmega2560) | ATmega2560 | yes | yes | - |
| LDO Leviathan V1.2 (Voron, F446) | STM32F446 | - | yes | - |
| MKS Monster8 V2 (F407) | STM32F407VE | yes | yes | - |
| MKS RUMBA32 V1.0 (F446) | STM32F446 | yes | yes | - |
| MKS Robin E3 (F103) | STM32F103 | yes | yes | - |
| MKS Robin Nano V2 (F103) | STM32F103 | yes | yes | - |
| MKS Robin Nano V3 (F407) | STM32F407VG | yes | yes | - |
| MKS Robin Nano V3.1 (F407) | STM32F407VE | yes | yes | - |
| MKS SGEN_L V1 (LPC1768) | LPC1768 | yes | yes | yes |
| MKS SGEN_L V2 (LPC1769) | LPC1769 | yes | yes | yes |
| Mellow Fly E3 V2 (F407) | STM32F407 | yes | yes | yes |
| Einsy Rambo (Prusa MK3 class, ATmega2560) | ATmega2560 | yes | yes | - |
| Mini Rambo (ATmega2560) | ATmega2560 | yes | yes | - |
| Rambo (ATmega2560) | ATmega2560 | yes | yes | - |

Anything under 100 shows exactly which gate failed and why.

## Support

HotFetched is free and self-hosted. If it saved you time or a bricked board, you can support development here:

[![Ko-fi](https://img.shields.io/badge/Ko--fi-Support%20HotFetched-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/bloodthirstycheeseburger90415)

**https://ko-fi.com/bloodthirstycheeseburger90415**

## License

MIT. Sound library: [ldrolez/free-midi-chords](https://github.com/ldrolez/free-midi-chords) (MIT).
