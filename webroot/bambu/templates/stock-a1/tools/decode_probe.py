#!/usr/bin/env python3
"""Decode eight 32-bit words read from 0x20003C00."""
from __future__ import annotations
import argparse, json, re, struct
from pathlib import Path

p = argparse.ArgumentParser()
p.add_argument('input', type=Path, help='raw 32-byte dump or text containing 32-bit hex words')
a = p.parse_args()
data = a.input.read_bytes()
if len(data) != 32:
    text = data.decode('utf-8', errors='ignore')
    words = [int(x, 16) for x in re.findall(r'(?:0x)?([0-9a-fA-F]{8})', text)]
    if len(words) < 8:
        raise SystemExit('Need 32 raw bytes or at least eight 32-bit hex words')
    words = words[:8]
else:
    words = list(struct.unpack('<8I', data))
magic, version, target, cpuid, heartbeat, msp, psp, control = words
result = {
    'valid_magic': magic == 0x43415043,
    'magic': f'0x{magic:08x}',
    'format_version': version,
    'target': {0x2168: 'SPC2168 mainboard MC', 0x1168: 'SPC1168 toolhead TH'}.get(target, f'unknown 0x{target:08x}'),
    'cpuid': f'0x{cpuid:08x}',
    'heartbeat': heartbeat,
    'msp': f'0x{msp:08x}',
    'psp': f'0x{psp:08x}',
    'control': f'0x{control:08x}',
}
print(json.dumps(result, indent=2))
