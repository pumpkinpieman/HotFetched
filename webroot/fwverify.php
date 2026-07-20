<?php
declare(strict_types=1);

/**
 * HotFetched — Firmware Verifier
 *
 * Reads a compiled firmware artifact and reports what is ACTUALLY in it, then
 * diffs that against the configuration HotFetched submitted.
 *
 * The approach is deliberately not "decompilation". Compilers discard most
 * boolean #defines entirely, so any tool claiming to recover them exactly is
 * guessing. What DOES survive is:
 *
 *   - String literals   (version, board name, machine name, build date, ...)
 *   - Numeric constants (steps/mm, feedrates, accelerations, currents, PID, ...)
 *     which Marlin stores as const arrays in flash (_DASU, _DMA, _DMF, ...)
 *   - Code/strings pulled in by a feature (evidence a feature was compiled)
 *
 * So every finding is reported at one of three confidence levels:
 *
 *   VERIFIED         - the exact value was found as a literal in the binary
 *   INFERRED         - a fingerprint strongly implies it, but is not proof
 *   NOT_DETERMINABLE - the compiler left no recoverable trace
 *
 * A verifier that reports guesses as facts is worse than no verifier.
 */

/* --------------------------------------------------------------- helpers */

/** Unpack a .uf2 into the raw image it carries (512-byte blocks, 256B payload). */
function fwv_uf2_to_bin(string $raw): string
{
    if (strlen($raw) < 512 || substr($raw, 0, 4) !== "UF2\n") {
        return $raw;
    }
    $out = '';
    for ($off = 0; $off + 512 <= strlen($raw); $off += 512) {
        $blk = substr($raw, $off, 512);
        $hdr = unpack('Vmagic1/Vmagic2/Vflags/Vaddr/Vpayload', $blk);
        if (($hdr['magic1'] ?? 0) !== 0x0A324655) {
            continue;
        }
        $len = min(476, max(0, (int)$hdr['payload']));
        $out .= substr($blk, 32, $len);
    }
    return $out !== '' ? $out : $raw;
}

/** Printable ASCII runs of at least $min chars. */
function fwv_strings(string $bin, int $min = 5): array
{
    preg_match_all('/[\x20-\x7E]{' . $min . ',}/', $bin, $m);
    return $m[0] ?? [];
}

/**
 * Search for a run of consecutive little-endian float32 values.
 * Returns byte offsets of every exact match.
 */
function fwv_find_floats(string $bin, array $vals): array
{
    $needle = '';
    foreach ($vals as $v) {
        $needle .= pack('g', (float)$v);   // 'g' = float32 LE
    }
    return fwv_find_all($bin, $needle);
}

/** Search for a run of consecutive little-endian uint32 values. */
function fwv_find_u32(string $bin, array $vals): array
{
    $needle = '';
    foreach ($vals as $v) {
        $needle .= pack('V', (int)$v);
    }
    return fwv_find_all($bin, $needle);
}

/** Search for a single float32 anywhere. */
function fwv_find_float(string $bin, float $v): array
{
    return fwv_find_all($bin, pack('g', $v));
}

function fwv_find_all(string $hay, string $needle): array
{
    if ($needle === '') {
        return [];
    }
    $hits = [];
    $off  = 0;
    while (($p = strpos($hay, $needle, $off)) !== false) {
        $hits[] = $p;
        $off = $p + 1;
        if (count($hits) > 50) {
            break;
        }
    }
    return $hits;
}

/* ------------------------------------------------------- identification */

/**
 * Work out which firmware this artifact is, from its own strings.
 * Returns ['firmware'=>marlin|klipper|reprap|unknown, 'evidence'=>[], ...]
 */
function fwv_identify(string $bin): array
{
    $s = implode("\n", fwv_strings($bin, 4));

    $out = ['firmware' => 'unknown', 'version' => null, 'evidence' => []];

    /* Known firmware families, in order of specificity. Each is a signature of
       strings that family reliably embeds. Unknown firmware still gets a full
       structural report - it is never a hard failure. */
    $families = [
        'marlin'    => ['Marlin'],
        'klipper'   => ['Klipper', 'klipper'],
        'reprap'    => ['RepRapFirmware', 'RepRap Firmware', 'Duet'],
        'smoothie'  => ['Smoothie', 'Smoothieware'],
        'repetier'  => ['Repetier'],
        'grbl'      => ['Grbl', 'GRBL'],
        'fluidnc'   => ['FluidNC'],
        'prusa'     => ['Prusa-Firmware', 'Original Prusa', 'buddy'],
        'kalico'    => ['Kalico'],
        'rrf_boot'  => ['Bootloader', 'bootloader'],
        'esp3d'     => ['ESP3D'],
        'katapult'  => ['Katapult', 'CanBoot'],
        'mks'       => ['MKS', 'Makerbase'],
        'creality'  => ['Creality'],
        'anycubic'  => ['Anycubic'],
    ];

    foreach ($families as $fam => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($s, $needle)) {
                $out['firmware']   = $fam;
                $out['evidence'][] = "Contains the string \"{$needle}\"";
                break 2;
            }
        }
    }

    /* Version + date: try hard regardless of family. */
    if (preg_match('/(bugfix-\d+\.\d+\.x)/', $s, $m)) {
        $out['version'] = $m[1];
    } elseif (preg_match('/\bv?(\d+\.\d+\.\d+(?:[-.\w]{0,20})?)/', $s, $m)) {
        $out['version'] = $m[1];
    }
    if (preg_match('/(20\d{2}-\d{2}-\d{2})/', $s, $m)) {
        $out['build_date'] = $m[1];
    } elseif (preg_match('/(20\d{2}\d{2}\d{2})\d{6}/', $s, $m)) {
        $out['build_date'] = $m[1];
    }

    /* MCU / architecture, useful for every family. */
    if (preg_match('/(stm32[a-z]?\d{3}[a-z0-9]*|lpc17\d\d|rp2040|rp2350|atmega\d+|esp32[a-z0-9-]*|samd\d+|at91sam\w+)/i', $s, $m)) {
        $out['mcu'] = strtolower($m[1]);
    }

    /* Toolchain / compiler fingerprint - present in most ARM binaries. */
    if (preg_match('/GCC[:\s][^\x00]{0,40}/', $s, $m)) {
        $out['toolchain'] = trim($m[0]);
    }

    return $out;
}

/* ------------------------------------------- Marlin-specific extraction */

/** Kinematics is reported by M115 as a literal string, so it survives. */
function fwv_marlin_kinematics(string $bin): ?string
{
    foreach (['COREXY', 'COREXZ', 'COREYZ', 'MARKFORGED_XY', 'DELTA', 'SCARA'] as $k) {
        if (str_contains($bin, $k)) {
            return $k;
        }
    }
    return null;
}

/**
 * Feature fingerprints. Each entry: feature key => list of strings whose
 * presence in the binary implies the feature was compiled in.
 *
 * These are INFERENCES. A string can appear for other reasons, and a feature
 * can exist without a unique string. Reported as INFERRED, never VERIFIED.
 */
function fwv_marlin_fingerprints(): array
{
    return [
        'bed_leveling'    => ['Bed Leveling', 'Mesh Bed Leveling', 'Level Bed', 'G29'],
        'ubl'             => ['Unified Bed Leveling', 'UBL'],
        'probe'           => ['Probe Offset', 'BLTouch', 'Z Probe'],
        'runout'          => ['Runout', 'Filament runout'],
        'advanced_pause'  => ['Advanced Pause', 'PARKING', 'Nozzle Parked'],
        'linear_advance'  => ['Linear Advance', 'Advance K'],
        'power_loss'      => ['Power-Loss', 'Outage', 'Resume Print'],
        'eeprom'          => ['EEPROM', 'Store Settings'],
        'mmu'             => ['MMU', 'Prusa MMU'],
        'input_shaping'   => ['Input Shaping', 'Shaping'],
        'ft_motion'       => ['Fixed-Time', 'FT Motion', 'ftMotion'],
        'babystepping'    => ['Babystep'],
        'fwretract'       => ['Retract', 'Firmware Retract'],
        'neopixel'        => ['NeoPixel', 'LED'],
        'tmc_drivers'     => ['TMC', 'StallGuard', 'Driver Current'],
        'sd_support'      => ['SD Card', 'Media', 'No Media'],
        'arc_support'     => ['Arc', 'G2/G3'],
        'nozzle_clean'    => ['Clean', 'Nozzle Clean'],
        'backlash'        => ['Backlash'],
        'case_light'      => ['Case Light'],
    ];
}

/**
 * Verify configured NUMERIC values against the binary. This is the strong part:
 * Marlin stores these as const arrays in flash, so an exact literal match is
 * genuine proof the value was compiled in.
 *
 * @param array $cfg  The values HotFetched submitted for this build.
 * @return array<int, array{key:string,label:string,expected:string,status:string,detail:string}>
 */
function fwv_marlin_verify_numbers(string $bin, array $cfg): array
{
    $rows = [];

    $check = function (string $key, string $label, ?array $vals, string $type)
             use (&$rows, $bin): void {
        if ($vals === null || in_array(null, $vals, true)) {
            return;
        }
        $hits = $type === 'f' ? fwv_find_floats($bin, $vals) : fwv_find_u32($bin, $vals);
        $pretty = implode(', ', array_map(fn ($v) => rtrim(rtrim(sprintf('%.3f', (float)$v), '0'), '.'), $vals));
        $rows[] = [
            'key'      => $key,
            'label'    => $label,
            'expected' => $pretty,
            'status'   => $hits !== [] ? 'VERIFIED' : 'NOT_FOUND',
            'detail'   => $hits !== []
                ? 'Found as a literal at 0x' . dechex($hits[0])
                : 'This exact value is not present in the binary',
        ];
    };

    $n = fn (string $k) => isset($cfg[$k]) && $cfg[$k] !== '' ? (float)$cfg[$k] : null;

    // DEFAULT_AXIS_STEPS_PER_UNIT { X, Y, Z, E } - float32[4]
    $check('steps', 'Steps per mm (X, Y, Z, E)',
        [$n('steps_x'), $n('steps_y'), $n('steps_z'), $n('steps_e')], 'f');

    // DEFAULT_MAX_FEEDRATE { X, Y, Z, E } - float32[4]
    $check('feedrate', 'Max feedrate (X, Y, Z, E)',
        [$n('feed_x'), $n('feed_y'), $n('feed_z'), $n('feed_e')], 'f');

    // DEFAULT_MAX_ACCELERATION { X, Y, Z, E } - uint32[4]
    $check('accel', 'Max acceleration (X, Y, Z, E)',
        [$n('accel_x'), $n('accel_y'), $n('accel_z'), $n('accel_e')], 'u');

    // TMC currents - int/uint arrays, checked individually (layout varies)
    foreach ([['cur_x', 'X'], ['cur_y', 'Y'], ['cur_z', 'Z'], ['cur_e0', 'E0']] as [$k, $ax]) {
        $v = $n($k);
        if ($v === null) {
            continue;
        }
        $hits = fwv_find_u32($bin, [(int)$v]);
        $rows[] = [
            'key' => $k, 'label' => "{$ax} motor current (mA)", 'expected' => (string)(int)$v,
            'status' => $hits !== [] ? 'VERIFIED' : 'NOT_FOUND',
            'detail' => $hits !== []
                ? 'Found as a literal at 0x' . dechex($hits[0])
                : 'Not found as a 32-bit literal (may be folded into an instruction)',
        ];
    }

    // Hotend/bed PID - float32
    foreach ([['pid_kp', 'Hotend Kp'], ['pid_ki', 'Hotend Ki'], ['pid_kd', 'Hotend Kd'],
              ['bed_kp', 'Bed Kp'], ['bed_ki', 'Bed Ki'], ['bed_kd', 'Bed Kd'],
              ['advance_k', 'Linear Advance K']] as [$k, $label]) {
        $v = $n($k);
        if ($v === null || $v == 0.0) {
            continue;
        }
        $hits = fwv_find_float($bin, (float)$v);
        $rows[] = [
            'key' => $k, 'label' => $label,
            'expected' => rtrim(rtrim(sprintf('%.4f', $v), '0'), '.'),
            'status' => $hits !== [] ? 'VERIFIED' : 'NOT_FOUND',
            'detail' => $hits !== []
                ? 'Found as a literal at 0x' . dechex($hits[0])
                : 'Not found (constant may have been folded by the optimizer)',
        ];
    }

    // Bed size - often int literals
    foreach ([['bed_x', 'Bed size X'], ['bed_y', 'Bed size Y'], ['z_max', 'Z height']] as [$k, $label]) {
        $v = $n($k);
        if ($v === null) {
            continue;
        }
        $hits = fwv_find_u32($bin, [(int)$v]);
        $rows[] = [
            'key' => $k, 'label' => $label, 'expected' => (string)(int)$v,
            'status' => $hits !== [] ? 'VERIFIED' : 'NOT_FOUND',
            'detail' => $hits !== [] ? 'Found as a literal at 0x' . dechex($hits[0])
                                      : 'Not found as a 32-bit literal',
        ];
    }

    return $rows;
}


/* -------------------------------------------- Universal (any firmware) */

/**
 * Structural facts that hold for ANY binary, whatever firmware it is.
 * This is what makes the tool useful on files it has never seen before.
 */
function fwv_universal(string $raw, string $bin): array
{
    $out = [];

    /* Container format */
    $fmt = 'raw binary';
    if (str_starts_with($raw, "UF2\n")) {
        $fmt = 'UF2 (unpacked to raw image for analysis)';
    } elseif (str_starts_with($raw, "\x7fELF")) {
        $fmt = 'ELF object';
    } elseif (preg_match('/^:[0-9A-Fa-f]{8}/', substr($raw, 0, 9))) {
        $fmt = 'Intel HEX';
    }
    $out[] = ['label' => 'Container format', 'value' => $fmt,
              'detail' => 'Determined from the file header'];

    /* ARM vector table: word 1 is the reset handler, word 0 the initial SP.
       This tells us the flash base address the image was linked for - which
       is exactly the thing that gets a board bricked when it is wrong. */
    if (strlen($bin) >= 8) {
        $v = unpack('Vsp/Vreset', substr($bin, 0, 8));
        $sp = $v['sp'] ?? 0;
        $rst = $v['reset'] ?? 0;
        // Valid ARM Cortex-M: SP in SRAM (0x2000_0000..), reset in flash with thumb bit
        if ($sp >= 0x20000000 && $sp <= 0x2FFFFFFF && ($rst & 1) === 1) {
            $base = $rst & 0xFFFF0000;
            $out[] = ['label' => 'ARM vector table', 'value' => sprintf('SP=0x%08X  Reset=0x%08X', $sp, $rst),
                      'detail' => 'Valid Cortex-M image. Linked for flash around 0x'
                                . strtoupper(dechex($base))];
            $out[] = ['label' => 'Flash base (linked)', 'value' => sprintf('0x%08X', $base),
                      'detail' => 'The offset this image expects. Flashing it at a different '
                                . 'offset will not boot.'];
        }
    }

    /* Entropy - distinguishes real code from encrypted/compressed blobs. */
    $sample = substr($bin, 0, 65536);
    $freq = count_chars($sample, 1);
    $len = strlen($sample);
    $H = 0.0;
    foreach ($freq as $n) {
        $p = $n / max(1, $len);
        $H -= $p * log($p, 2);
    }
    $ent = round($H, 2);
    $entNote = $ent > 7.5
        ? 'Very high - this looks encrypted or compressed, so little can be read from it'
        : ($ent < 3.0 ? 'Very low - mostly padding or empty' : 'Normal for compiled code');
    $out[] = ['label' => 'Entropy', 'value' => $ent . ' bits/byte', 'detail' => $entNote];

    /* Printable strings give a rough sense of how much is readable. */
    $strs = fwv_strings($bin, 6);
    $out[] = ['label' => 'Readable strings', 'value' => number_format(count($strs)),
              'detail' => 'Text literals recoverable from the image'];

    return $out;
}

/** Notable strings worth showing the user verbatim (version banners, URLs, board names). */
function fwv_notable_strings(string $bin, int $limit = 25): array
{
    $out = [];
    foreach (fwv_strings($bin, 6) as $s) {
        $s = trim($s);
        if (strlen($s) > 90) {
            continue;
        }
        // Things that look like identity: versions, dates, board names, URLs, banners.
        if (preg_match('#(v?\d+\.\d+\.\d+|20\d\d-\d\d-\d\d|https?://|BOARD_|BTT|SKR|Octopus|Manta|Ender|RAMPS|Rambo|firmware|Firmware|FIRMWARE|MACHINE|Printer|printer)#', $s)) {
            $out[$s] = true;
            if (count($out) >= $limit) {
                break;
            }
        }
    }
    return array_keys($out);
}


/* ------------------------------------ Signatures, archives and manifests */

/**
 * Identify a detached signature. These carry NO firmware - they authenticate a
 * companion file - so we say what the signature is rather than pretending to
 * read machine settings out of it.
 */
function fwv_identify_signature(string $raw): ?array
{
    $len = strlen($raw);
    $out = null;

    // PGP / GPG armored
    if (str_contains($raw, '-----BEGIN PGP SIGNATURE-----')) {
        $out = ['type' => 'PGP/GPG signature (ASCII-armored)', 'algo' => 'OpenPGP', 'bytes' => $len];
    }
    // PGP binary packet: first byte 0x88/0x89 (signature packet tag)
    elseif ($len > 2 && in_array(ord($raw[0]), [0x88, 0x89, 0x90], true)) {
        $out = ['type' => 'PGP/GPG signature (binary)', 'algo' => 'OpenPGP', 'bytes' => $len];
    }
    // DER-encoded (X.509 / PKCS#7 / CMS) starts with SEQUENCE 0x30
    elseif ($len > 2 && ord($raw[0]) === 0x30) {
        $out = ['type' => 'DER/PKCS#7 signature', 'algo' => 'X.509 / CMS', 'bytes' => $len];
    }
    // Raw signature blobs by size
    elseif ($len === 64) {
        $out = ['type' => 'Raw detached signature', 'algo' => 'Ed25519 (64 bytes)', 'bytes' => $len];
    } elseif ($len === 256) {
        $out = ['type' => 'Raw detached signature', 'algo' => 'RSA-2048 (256 bytes)', 'bytes' => $len];
    } elseif ($len === 512) {
        $out = ['type' => 'Raw detached signature', 'algo' => 'RSA-4096 (512 bytes)', 'bytes' => $len];
    } elseif ($len > 0 && $len <= 1024 && strlen(trim($raw)) === $len) {
        // Small + maybe hex/base64 text
        if (preg_match('/^[0-9a-fA-F\s]+$/', $raw)) {
            $out = ['type' => 'Hex-encoded signature or checksum', 'algo' => 'unknown', 'bytes' => $len];
        } elseif (preg_match('#^[A-Za-z0-9+/=\s]+$#', $raw)) {
            $out = ['type' => 'Base64-encoded signature', 'algo' => 'unknown', 'bytes' => $len];
        }
    }
    return $out;
}

/** Parse a firmware manifest (.json / .json.sig payload). */
function fwv_parse_manifest(string $raw): ?array
{
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return null;
    }
    $rows  = [];
    $flat  = [];
    $walk  = function ($arr, string $prefix = '') use (&$walk, &$flat): void {
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                $walk($v, $key);
            } elseif (is_scalar($v) || $v === null) {
                $flat[$key] = (string)$v;
            }
        }
    };
    $walk($j);

    // Surface the fields that actually matter in a firmware manifest.
    $interesting = ['version', 'board', 'model', 'hardware', 'mcu', 'date', 'build',
                    'sha', 'sha256', 'md5', 'checksum', 'hash', 'file', 'filename',
                    'size', 'firmware', 'product', 'name', 'revision', 'variant'];
    foreach ($flat as $k => $v) {
        foreach ($interesting as $want) {
            if (stripos($k, $want) !== false) {
                $rows[] = ['label' => $k, 'value' => mb_substr($v, 0, 120),
                           'detail' => 'From the firmware manifest'];
                break;
            }
        }
    }
    return ['fields' => $rows, 'count' => count($flat)];
}

/**
 * Pull the firmware out of an archive. Vendors commonly ship
 * firmware.zip containing firmware.bin + a manifest + a signature.
 *
 * @return array{bin:?string, name:?string, listing:array, manifest:?array, signature:?array}
 */
function fwv_open_archive(string $raw): array
{
    $res = ['bin' => null, 'name' => null, 'listing' => [], 'manifest' => null, 'signature' => null];

    $tmp = tempnam(sys_get_temp_dir(), 'fwzip');
    if ($tmp === false || @file_put_contents($tmp, $raw) === false) {
        return $res;
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return $res;
    }

    $best = null;
    $bestSize = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) {
            continue;
        }
        $name = (string)$st['name'];
        $size = (int)$st['size'];
        $res['listing'][] = ['name' => $name, 'size' => $size];
        $low = strtolower($name);

        if (str_ends_with($low, '.sig') || str_ends_with($low, '.asc')) {
            $s = $zip->getFromIndex($i);
            if ($s !== false && $res['signature'] === null) {
                $res['signature'] = fwv_identify_signature($s);
                if ($res['signature'] !== null) {
                    $res['signature']['file'] = $name;
                }
            }
            continue;
        }
        if (str_ends_with($low, '.json')) {
            $s = $zip->getFromIndex($i);
            if ($s !== false && $res['manifest'] === null) {
                $res['manifest'] = fwv_parse_manifest($s);
            }
            continue;
        }
        // Firmware candidates: keep the largest .bin/.uf2/.hex/.elf
        if (preg_match('/\.(bin|uf2|hex|elf|img)$/', $low) && $size > $bestSize) {
            $best = $i;
            $bestSize = $size;
        }
    }
    if ($best !== null) {
        $data = $zip->getFromIndex($best);
        if ($data !== false && strlen($data) > 256) {
            $st = $zip->statIndex($best);
            $res['bin']  = $data;
            $res['name'] = (string)($st['name'] ?? 'firmware.bin');
        }
    }
    $zip->close();
    @unlink($tmp);
    return $res;
}

/** Trailing-signature detection: some vendors append a sig to firmware.bin. */
function fwv_trailing_signature(string $bin): ?array
{
    $len = strlen($bin);
    foreach ([64, 256, 512] as $sz) {
        if ($len <= $sz + 1024) {
            continue;
        }
        $tail = substr($bin, -$sz);
        // A signature is high-entropy; firmware padding is usually 0xFF or 0x00.
        $uniq = count(count_chars($tail, 1));
        if ($uniq > $sz / 3) {
            $pad = substr($bin, -($sz + 16), 16);
            if (trim($pad, "\xFF") === '' || trim($pad, "\x00") === '') {
                return ['type' => 'Possible appended signature',
                        'algo' => $sz === 64 ? 'Ed25519' : ($sz === 256 ? 'RSA-2048' : 'RSA-4096'),
                        'bytes' => $sz];
            }
        }
    }
    return null;
}

/* ------------------------------------------------------------ main entry */

/**
 * Analyse a firmware artifact.
 *
 * @param string     $raw   Raw bytes of firmware.bin / klipper.bin / .uf2 / .elf
 * @param array|null $cfg   Values HotFetched submitted (null = arbitrary firmware)
 * @param array|null $board Board definition (null = unknown)
 */
function fwv_analyse(string $raw, ?array $cfg = null, ?array $board = null,
                    string $filename = ''): array
{
    $pre = [];      // findings about the container itself
    $low = strtolower($filename);

    /* ---- ZIP: pull the firmware out and analyse THAT ---- */
    if (str_starts_with($raw, "PK\x03\x04") || str_ends_with($low, '.zip') || str_ends_with($low, '.zip.sig')) {
        $arc = fwv_open_archive($raw);
        if ($arc['listing'] !== []) {
            $names = array_map(fn ($f) => $f['name'] . ' (' . number_format($f['size']) . ' B)', $arc['listing']);
            $pre[] = ['label' => 'Archive contents', 'value' => count($arc['listing']) . ' file(s)',
                      'detail' => implode(', ', array_slice($names, 0, 12))];
            if ($arc['signature'] !== null) {
                $pre[] = ['label' => 'Signature in archive',
                          'value' => $arc['signature']['algo'] ?? 'unknown',
                          'detail' => ($arc['signature']['file'] ?? '') . ' - '
                                    . ($arc['signature']['type'] ?? '')];
            }
            if ($arc['manifest'] !== null) {
                foreach ($arc['manifest']['fields'] as $row) {
                    $pre[] = $row;
                }
            }
            if ($arc['bin'] !== null) {
                $pre[] = ['label' => 'Firmware extracted from archive', 'value' => (string)$arc['name'],
                          'detail' => 'Analysis below is of this file'];
                $raw = $arc['bin'];   // analyse the firmware inside
            } else {
                // Archive with no firmware in it - still report what it holds.
                return [
                    'size' => strlen($raw), 'identity' => ['firmware' => 'archive', 'evidence' => []],
                    'verified' => $pre, 'inferred' => [], 'unknown' => [], 'mismatches' => [],
                    'structure' => [], 'strings' => [],
                    'notes' => ['This archive contains no firmware image (.bin/.uf2/.hex/.elf). '
                              . 'Its contents are listed above.'],
                ];
            }
        }
    }

    /* ---- Detached signature: it carries no firmware. Say so plainly. ---- */
    $sigOnly = fwv_identify_signature($raw);
    if ($sigOnly !== null && (str_ends_with($low, '.sig') || str_ends_with($low, '.asc'))
        && strlen($raw) < 8192) {
        return [
            'size'     => strlen($raw),
            'identity' => ['firmware' => 'signature', 'evidence' => [$sigOnly['type']]],
            'verified' => [
                ['label' => 'Signature type', 'value' => $sigOnly['type'], 'detail' => 'Detected from the file structure'],
                ['label' => 'Algorithm', 'value' => $sigOnly['algo'], 'detail' => $sigOnly['bytes'] . ' bytes'],
            ],
            'inferred' => [], 'unknown' => [], 'mismatches' => [], 'structure' => [], 'strings' => [],
            'notes' => ['This is a DETACHED SIGNATURE, not firmware. It authenticates a companion '
                      . 'file - it does not contain one. Upload the firmware it signs '
                      . '(the same name without .sig) to analyse the actual image. '
                      . 'Verifying the signature itself would need the vendor\'s public key, '
                      . 'which is not included in the signature file.'],
        ];
    }

    /* ---- JSON manifest ---- */
    // chr(123) is the opening-brace character; written this way so brace-counting stays valid.
    if (str_starts_with(ltrim($raw), chr(123)) || str_ends_with($low, '.json') || str_ends_with($low, '.json.sig')) {
        $man = fwv_parse_manifest($raw);
        if ($man !== null && $man['fields'] !== []) {
            return [
                'size' => strlen($raw),
                'identity' => ['firmware' => 'manifest', 'evidence' => ['Valid JSON manifest']],
                'verified' => $man['fields'], 'inferred' => [], 'unknown' => [], 'mismatches' => [],
                'structure' => [], 'strings' => [],
                'notes' => ['This is a firmware MANIFEST, not firmware. It describes an image '
                          . '(version, board, checksums) but contains no code. Upload the image '
                          . 'it refers to in order to analyse the firmware itself.'],
            ];
        }
    }

    $bin = fwv_uf2_to_bin($raw);

    $r = [
        'size'      => strlen($raw),
        'identity'  => fwv_identify($bin),
        'verified'  => [],
        'inferred'  => [],
        'unknown'   => [],
        'mismatches'=> [],
        'notes'     => [],
        'structure' => array_merge($pre, fwv_universal($raw, $bin)),
        'strings'   => fwv_notable_strings($bin),
    ];

    // Some vendors append a signature to the end of firmware.bin.
    $trail = fwv_trailing_signature($bin);
    if ($trail !== null) {
        $r['structure'][] = ['label' => 'Appended signature', 'value' => $trail['algo'],
                             'detail' => $trail['bytes'] . ' bytes at the end of the image - '
                                       . 'this firmware appears to be signed in place'];
    }

    $fw = $r['identity']['firmware'];

    /* ---- Board identity (string literal - strong evidence) ---- */
    if ($board !== null) {
        $mb = (string)($board['marlin']['motherboard'] ?? '');
        $short = str_replace('BOARD_', '', $mb);
        if ($short !== '' && stripos($bin, $short) !== false) {
            $r['verified'][] = ['label' => 'Board', 'value' => $board['name'],
                                'detail' => "Board name \"{$short}\" is present in the binary"];
        } elseif ($short !== '') {
            $r['inferred'][] = ['label' => 'Board', 'value' => 'could not confirm',
                                'detail' => "Board string \"{$short}\" not found - Marlin does not always embed it"];
        }
    }

    if ($fw === 'marlin') {
        /* ---- Numeric verification: the strong signal ---- */
        if ($cfg !== null) {
            foreach (fwv_marlin_verify_numbers($bin, $cfg) as $row) {
                if ($row['status'] === 'VERIFIED') {
                    $r['verified'][] = ['label' => $row['label'], 'value' => $row['expected'],
                                        'detail' => $row['detail']];
                } else {
                    // A configured value absent from the binary is the interesting case:
                    // either the optimizer folded it, or it was never applied.
                    $r['mismatches'][] = ['label' => $row['label'], 'expected' => $row['expected'],
                                          'detail' => $row['detail']];
                }
            }
        }

        /* ---- Kinematics: compiled as a literal string ---- */
        $kin = fwv_marlin_kinematics($bin);
        if ($kin !== null) {
            $r['verified'][] = ['label' => 'Kinematics', 'value' => $kin,
                                'detail' => 'Kinematics name found as a string literal'];
            if ($cfg !== null && isset($cfg['kinematics'])) {
                $want = strtoupper((string)$cfg['kinematics']);
                if ($want !== '' && $want !== 'CARTESIAN' && $want !== $kin) {
                    $r['mismatches'][] = ['label' => 'Kinematics',
                        'expected' => $want, 'detail' => "Binary reports {$kin}"];
                }
            }
        }

        /* ---- Feature fingerprints: INFERRED, never claimed as proof ---- */
        foreach (fwv_marlin_fingerprints() as $feat => $needles) {
            $found = null;
            foreach ($needles as $s) {
                if (stripos($bin, $s) !== false) {
                    $found = $s;
                    break;
                }
            }
            if ($found !== null) {
                $r['inferred'][] = ['label' => $feat, 'value' => 'present',
                                    'detail' => "Fingerprint \"{$found}\" found in the binary"];
            }
        }

        $r['unknown'][] = 'Boolean #defines with no string or numeric footprint '
                        . '(e.g. INVERT_X_DIR, endstop hit states, S_CURVE_ACCELERATION) '
                        . 'leave no recoverable trace once compiled. They cannot be read back '
                        . 'from any binary, by any tool.';
    } elseif ($fw === 'klipper') {
        $r['notes'][] = 'Klipper keeps its machine configuration in printer.cfg on the HOST, '
                      . 'not in the MCU binary. The binary only carries the MCU/architecture '
                      . 'build options, so steps, currents and limits cannot be verified from it - '
                      . 'they are not in there.';
        if (isset($r['identity']['mcu'])) {
            $r['verified'][] = ['label' => 'MCU', 'value' => $r['identity']['mcu'],
                                'detail' => 'MCU name found in the binary'];
            if ($board !== null) {
                $want = strtolower((string)($board['mcu_variants'][0]['klipper_mcu'] ?? ''));
                if ($want !== '' && !str_contains($r['identity']['mcu'], substr($want, 0, 8))) {
                    $r['mismatches'][] = ['label' => 'MCU',
                        'expected' => $want,
                        'detail' => 'Binary reports ' . $r['identity']['mcu']
                                  . ' - this firmware may be for a DIFFERENT board'];
                }
            }
        }
    } elseif ($fw === 'reprap') {
        $r['notes'][] = 'RepRapFirmware is configured at runtime by config.g. The binary is a '
                      . 'prebuilt, board-specific image and carries no machine settings, so only '
                      . 'its identity and version can be checked here.';
    } else {
        // Not a family we recognise. Still report everything the binary itself
        // proves: container format, linked flash base, entropy, and its strings.
        $r['notes'][] = 'This firmware is not one of the families this tool has fingerprints for '
                      . '(Marlin, Klipper, RepRapFirmware, Smoothieware, Repetier, Grbl/FluidNC, '
                      . 'Prusa, Katapult). The structural analysis below still applies to it: '
                      . 'container format, the flash offset it was linked for, entropy, and the '
                      . 'identity strings found inside. Machine settings cannot be read from an '
                      . 'unknown firmware, because there is no known layout to read them from.';

        // Even so, if a config was supplied we can still search for its values as
        // literals. A hit is meaningful whatever the firmware.
        if ($cfg !== null) {
            foreach (fwv_marlin_verify_numbers($bin, $cfg) as $row) {
                if ($row['status'] === 'VERIFIED') {
                    $r['verified'][] = ['label' => $row['label'] . ' (literal match)',
                                        'value' => $row['expected'], 'detail' => $row['detail']];
                }
            }
        }
    }

    return $r;
}
