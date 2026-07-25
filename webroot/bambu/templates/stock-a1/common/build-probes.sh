#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
OUT="$ROOT/prebuilt"
mkdir -p "$OUT/spc2168" "$OUT/spc1168"

if command -v arm-none-eabi-gcc >/dev/null 2>&1; then
  CC=arm-none-eabi-gcc
  LD=arm-none-eabi-gcc
  OBJCOPY=arm-none-eabi-objcopy
  CFLAGS='-mcpu=cortex-m4 -mthumb -ffreestanding -fno-builtin -nostdlib'
  LDFLAGS='-mcpu=cortex-m4 -mthumb -nostdlib -Wl,--gc-sections'
elif command -v clang >/dev/null 2>&1 && command -v ld.lld >/dev/null 2>&1 && command -v llvm-objcopy >/dev/null 2>&1; then
  CC=clang
  LD=ld.lld
  OBJCOPY=llvm-objcopy
  CFLAGS='--target=arm-none-eabi -mcpu=cortex-m4 -mthumb -ffreestanding -fno-builtin -nostdlib'
  LDFLAGS=''
else
  echo 'Need arm-none-eabi-gcc/objcopy or clang + ld.lld + llvm-objcopy.' >&2
  exit 2
fi

build_one() {
  name=$1
  target=$2
  dir="$OUT/$name"
  "$CC" $CFLAGS -x assembler-with-cpp -DTARGET_ID="$target" -c "$ROOT/common/ram_probe.S" -o "$dir/probe_ram.o"
  if [ "$LD" = arm-none-eabi-gcc ]; then
    "$LD" $LDFLAGS -T "$ROOT/common/ram.ld" "$dir/probe_ram.o" -o "$dir/probe_ram.elf"
  else
    "$LD" -T "$ROOT/common/ram.ld" "$dir/probe_ram.o" -o "$dir/probe_ram.elf"
  fi
  "$OBJCOPY" -O ihex "$dir/probe_ram.elf" "$dir/probe_ram.hex"
  "$OBJCOPY" -O binary "$dir/probe_ram.elf" "$dir/probe_ram.bin"
  sha256sum "$dir/probe_ram.elf" "$dir/probe_ram.hex" "$dir/probe_ram.bin" > "$dir/SHA256SUMS"
}

build_one spc2168 0x00002168
build_one spc1168 0x00001168
echo "Built RAM-only probes under $OUT"
