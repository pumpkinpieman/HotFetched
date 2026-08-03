<?php
declare(strict_types=1);

/** Pure validation and analysis helpers for HotFetched power planning. */

function hf_power_default(): array
{
    return ['psus' => [], 'loads' => [], 'derating' => 80];
}

function hf_power_number(mixed $value, float $default, float $min, float $max): float
{
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }
    }
    if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
        return $default;
    }
    $number = (float)$value;
    if (!is_finite($number)) {
        return $default;
    }
    return max($min, min($max, $number));
}

function hf_power_text(mixed $value, int $maxChars): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    if ($maxChars < 1 || $text === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxChars, 'UTF-8');
    }
    if (preg_match_all('/./us', $text, $chars) === false) {
        return substr($text, 0, $maxChars);
    }
    return implode('', array_slice($chars[0], 0, $maxChars));
}

function hf_power_id(mixed $value): string
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$value) ?? '';
}

function hf_power_unique_id(string $candidate, string $prefix, int $position, array &$used): string
{
    $base = $candidate !== '' ? $candidate : $prefix . '_' . $position;
    $id = $base;
    $suffix = 2;
    while (isset($used[$id])) {
        $id = $base . '_' . $suffix++;
    }
    $used[$id] = true;
    return $id;
}

function hf_power_load_type_label(string $type): string
{
    return [
        'heated_bed' => 'Heated bed',
        'hotend' => 'Hotend heater',
        'mainboard' => 'Mainboard / electronics',
        'stepper' => 'Stepper motor',
        'toolboard' => 'CAN / toolboard',
        'fan' => 'Fan / blower',
        'raspberry_pi' => 'Raspberry Pi',
        'display' => 'Display',
        'mmu' => 'MMU',
        'led' => 'LED lighting',
        'buck' => 'DC converter output load',
        'other' => 'Other',
    ][$type] ?? 'Other';
}

function hf_power_typical_factor(string $type): float
{
    return [
        'heated_bed' => 0.75,
        'hotend' => 0.45,
        'mainboard' => 0.65,
        'stepper' => 0.55,
        'toolboard' => 0.65,
        'fan' => 0.70,
        'raspberry_pi' => 0.70,
        'display' => 0.70,
        'mmu' => 0.55,
        'led' => 0.75,
        'buck' => 0.70,
        'other' => 0.70,
    ][$type] ?? 0.70;
}

function hf_power_clean(array $power): array
{
    $out = hf_power_default();
    $usedPsu = [];
    $firstPsuIdMap = [];

    foreach (array_slice((array)($power['psus'] ?? []), 0, 12) as $index => $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $oldId = hf_power_id($raw['id'] ?? '');
        $id = hf_power_unique_id($oldId, 'psu', $index + 1, $usedPsu);
        if ($oldId !== '' && !isset($firstPsuIdMap[$oldId])) {
            $firstPsuIdMap[$oldId] = $id;
        }
        $out['psus'][] = [
            'id' => $id,
            'model' => hf_power_text($raw['model'] ?? '', 80),
            'input_voltage' => hf_power_number($raw['input_voltage'] ?? 120, 120, 0, 260),
            'output_voltage' => hf_power_number($raw['output_voltage'] ?? 12, 12, 0, 100),
            'max_current' => hf_power_number($raw['max_current'] ?? 0, 0, 0, 500),
            'rated_power' => hf_power_number($raw['rated_power'] ?? 0, 0, 0, 10000),
            'derating_percent' => hf_power_number($raw['derating_percent'] ?? 80, 80, 50, 100),
            'assignment' => hf_power_text($raw['assignment'] ?? '', 120),
        ];
    }

    $validTypes = ['heated_bed', 'hotend', 'mainboard', 'stepper', 'toolboard', 'fan', 'raspberry_pi', 'display', 'mmu', 'led', 'buck', 'other'];
    $usedLoad = [];
    foreach (array_slice((array)($power['loads'] ?? []), 0, 100) as $index => $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $type = (string)($raw['type'] ?? 'other');
        if (!in_array($type, $validTypes, true)) {
            $type = 'other';
        }
        $supplyType = (string)($raw['supply_type'] ?? 'dc');
        if (!in_array($supplyType, ['dc', 'ac'], true)) {
            $supplyType = 'dc';
        }
        $name = hf_power_text($raw['name'] ?? '', 100);
        $typical = hf_power_number($raw['typical_watts'] ?? 0, 0, 0, 10000);
        $peak = hf_power_number($raw['peak_watts'] ?? 0, 0, 0, 10000);
        $assigned = hf_power_id($raw['assigned_psu'] ?? '');
        if (isset($firstPsuIdMap[$assigned])) {
            $assigned = $firstPsuIdMap[$assigned];
        }
        if ($supplyType === 'ac') {
            $assigned = '';
        }

        // An untouched draft row has no meaningful user data. Default type,
        // voltage and quantity do not make a load meaningful by themselves.
        if ($name === '' && $typical <= 0 && $peak <= 0 && $assigned === '') {
            continue;
        }

        $oldId = hf_power_id($raw['id'] ?? '');
        $id = hf_power_unique_id($oldId, 'load', $index + 1, $usedLoad);
        $out['loads'][] = [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'supply_type' => $supplyType,
            'voltage' => hf_power_number($raw['voltage'] ?? 12, 12, 0, 260),
            'quantity' => (int)hf_power_number($raw['quantity'] ?? 1, 1, 1, 100),
            'typical_watts' => $typical,
            'peak_watts' => $peak,
            'assigned_psu' => $assigned,
        ];
    }

    return $out;
}

function hf_power_analyze(array $rawPower): array
{
    $power = hf_power_clean($rawPower);
    $errors = [];
    $warnings = [];
    $requirements = [];
    $notes = [];
    $psus = [];
    $loadsByPsu = [];

    foreach ($power['psus'] as $index => $psu) {
        $number = $index + 1;
        $model = $psu['model'] !== '' ? $psu['model'] : 'Unspecified model';
        $label = 'PSU #' . $number . ' — ' . $model;
        $voltage = (float)$psu['output_voltage'];
        $amps = (float)$psu['max_current'];
        $enteredRated = (float)$psu['rated_power'];

        if ($voltage <= 0 || $amps <= 0 || $enteredRated <= 0) {
            $notes[] = "PSU #{$number} is incomplete and was excluded from the graph. Enter output voltage, maximum current, and rated power to include it.";
            continue;
        }

        $inputVoltage = (float)$psu['input_voltage'];
        if ($inputVoltage <= 0) {
            $warnings[] = "{$label}: AC input voltage is not entered. Verify the PSU mains selector/range before energizing it.";
        } elseif ($inputVoltage < 90) {
            $warnings[] = "{$label}: {$inputVoltage} V AC input is outside the expected mains range. Verify the label and voltage selector.";
        }

        $vaCapacity = $voltage * $amps;
        $effectiveRated = min($enteredRated, $vaCapacity);
        $difference = abs($enteredRated - $vaCapacity);
        if ($difference > max(10.0, 0.10 * max($enteredRated, $vaCapacity))) {
            $warnings[] = "{$label}: entered wattage does not closely match volts × amps; the lower {$effectiveRated} W capacity is used.";
        }

        $psus[$psu['id']] = $psu + [
            'index' => $number,
            'label' => $label,
            'effective_rated' => $effectiveRated,
        ];
        $loadsByPsu[$psu['id']] = ['typical' => 0.0, 'peak' => 0.0, 'loads' => []];
    }

    foreach ($power['loads'] as $index => $load) {
        $displayName = $load['name'] !== ''
            ? $load['name']
            : 'Load #' . ($index + 1) . ' — ' . hf_power_load_type_label($load['type']);
        if ($load['name'] === '') {
            $warnings[] = "{$displayName} has no descriptive name.";
        }

        $quantity = (int)$load['quantity'];
        $typicalEach = (float)$load['typical_watts'];
        $peakEach = (float)$load['peak_watts'];
        if ($typicalEach <= 0 && $peakEach > 0) {
            $typicalEach = $peakEach * hf_power_typical_factor($load['type']);
            $notes[] = "{$displayName}: typical demand was estimated from peak wattage for the graph.";
        }
        if ($peakEach <= 0 && $typicalEach > 0) {
            $warnings[] = "{$displayName} has no peak wattage; its typical wattage is used as the minimum peak demand.";
        } elseif ($peakEach <= 0 && $typicalEach <= 0) {
            $warnings[] = "{$displayName} has no wattage data, so capacity cannot be fully verified.";
        }
        if ($typicalEach > $peakEach && $peakEach > 0) {
            $warnings[] = "{$displayName} has typical wattage above peak wattage; the higher value is used for peak-demand validation.";
        }

        $typical = $typicalEach * $quantity;
        $peak = max($peakEach, $typicalEach) * $quantity;
        $loadVoltage = (float)$load['voltage'];

        if ($load['supply_type'] === 'ac') {
            if ($loadVoltage <= 0) {
                $errors[] = "{$displayName} requires an AC voltage value.";
            }
            if ($load['type'] === 'heated_bed') {
                $requirements[] = "{$displayName} is an AC heated bed: use a correctly rated zero-cross AC SSR, thermal fuse, protective earth, enclosed mains terminals, and independent over-temperature protection.";
            }
            $notes[] = "{$displayName} is an AC-mains load and is excluded from the DC PSU graph.";
            continue;
        }

        if ($loadVoltage <= 0) {
            $errors[] = "{$displayName} requires a DC voltage value.";
            continue;
        }

        $psuId = (string)$load['assigned_psu'];
        if ($psuId === '') {
            $errors[] = "{$displayName} is not assigned to a power supply.";
            continue;
        }
        if (!isset($psus[$psuId])) {
            $known = array_values(array_filter(
                $power['psus'],
                static fn(array $candidate): bool => (string)$candidate['id'] === $psuId
            ));
            if ($known) {
                $errors[] = "{$displayName} is assigned to an incomplete power supply. Enter that PSU's output voltage, maximum current, and rated power before validating this load.";
            } else {
                $errors[] = "{$displayName} is assigned to a power supply that no longer exists. Reassign the load.";
            }
            continue;
        }

        $psu = $psus[$psuId];
        $psuVoltage = (float)$psu['output_voltage'];
        $conversionFactor = 1.0;
        if (abs($loadVoltage - $psuVoltage) > 0.6) {
            if ($loadVoltage < $psuVoltage) {
                $conversionFactor = 1.0 / 0.90;
                $requirements[] = "{$displayName} requires {$loadVoltage} V but {$psu['label']} supplies {$psuVoltage} V. Add a suitably rated buck converter; the graph budgets approximately 90% conversion efficiency.";
            } else {
                $conversionFactor = 1.0 / 0.85;
                $errors[] = "{$displayName} requires {$loadVoltage} V but {$psu['label']} supplies only {$psuVoltage} V. Reassign it or add a compatible power supply/boost stage.";
            }
        }

        $loadsByPsu[$psuId]['typical'] += $typical * $conversionFactor;
        $loadsByPsu[$psuId]['peak'] += $peak * $conversionFactor;
        $loadsByPsu[$psuId]['loads'][] = $displayName;

        $loadPeakAmps = $peak > 0 ? $peak / $loadVoltage : 0.0;
        if ($load['type'] === 'heated_bed') {
            if ($loadPeakAmps > 15) {
                $requirements[] = "{$displayName} is approximately " . number_format($loadPeakAmps, 1) . " A at peak. Use an external high-current DC MOSFET module or PWM-compatible DC SSR with substantial margin; do not route this load directly through a typical controller-board bed output.";
            } elseif ($loadPeakAmps > 10) {
                $requirements[] = "{$displayName} is approximately " . number_format($loadPeakAmps, 1) . " A. Verify the controller bed-output rating; an external MOSFET module is recommended.";
            }
            if ($peak > 0) {
                $requirements[] = "{$displayName} requires a correctly rated fuse, heater wiring/connectors, thermal fuse, and firmware thermal-runaway protection.";
            }
        } elseif ($load['type'] === 'hotend' && $peak > 0) {
            $requirements[] = "{$displayName} requires a correctly rated fuse, heater wiring/connectors, and firmware thermal-runaway protection.";
            if ($loadPeakAmps > 10) {
                $requirements[] = "{$displayName} is approximately " . number_format($loadPeakAmps, 1) . " A. Verify the controller heater-output rating or use an external MOSFET stage.";
            }
        }
    }

    $rows = [];
    foreach ($psus as $id => $psu) {
        $rated = (float)$psu['effective_rated'];
        $ceiling = $rated * ((float)$psu['derating_percent'] / 100.0);
        $peak = $loadsByPsu[$id]['peak'];
        $typical = $loadsByPsu[$id]['typical'];
        $headroom = $ceiling - $peak;
        $status = $peak > $rated ? 'error' : ($peak > $ceiling ? 'warning' : 'ok');

        if ($peak > $rated) {
            $neededRated = $peak / max(0.01, (float)$psu['derating_percent'] / 100.0);
            $additional = max(0.0, $neededRated - $rated);
            $errors[] = "{$psu['label']} will not generate enough power to run this build: estimated peak is " . number_format($peak, 0) . " W versus " . number_format($rated, 0) . " W effective rated capacity. Add approximately " . number_format($additional, 0) . " W or more at {$psu['output_voltage']} V, or move loads to another compatible supply.";
        } elseif ($peak > $ceiling) {
            $neededRated = $peak / max(0.01, (float)$psu['derating_percent'] / 100.0);
            $additional = max(0.0, $neededRated - $rated);
            $warnings[] = "{$psu['label']} exceeds its {$psu['derating_percent']}% planning ceiling: estimated peak is " . number_format($peak, 0) . " W versus " . number_format($ceiling, 0) . " W. Add approximately " . number_format($additional, 0) . " W of rated capacity or redistribute loads.";
        }

        $peakAmps = (float)$psu['output_voltage'] > 0 ? $peak / (float)$psu['output_voltage'] : 0.0;
        if ($peakAmps > (float)$psu['max_current'] + 0.01) {
            $errors[] = "{$psu['label']} exceeds its {$psu['max_current']} A current rating at an estimated " . number_format($peakAmps, 1) . " A peak.";
        }
        if ($peak <= 0.0) {
            $notes[] = "{$psu['label']} currently has no assigned DC load.";
        }

        $rows[] = [
            'id' => $id,
            'label' => $psu['label'],
            'output_voltage' => (float)$psu['output_voltage'],
            'rated_w' => $rated,
            'ceiling_w' => $ceiling,
            'typical_w' => $typical,
            'peak_w' => $peak,
            'peak_a' => $peakAmps,
            'headroom_w' => $headroom,
            'status' => $status,
        ];
    }

    if (!$power['loads']) {
        $notes[] = 'No electrical loads are defined yet. This graph shows PSU capacity only; add loads to verify whether the build has enough power and which support hardware is required.';
    }
    if (count($power['psus']) > 1) {
        $notes[] = 'Multiple PSU outputs must not be paralleled unless the supplies explicitly support current sharing. Bond protective earth correctly and use coordinated switching and fusing.';
    }

    return [
        'psus' => $rows,
        'errors' => array_values(array_unique($errors)),
        'warnings' => array_values(array_unique($warnings)),
        'requirements' => array_values(array_unique($requirements)),
        'notes' => array_values(array_unique($notes)),
    ];
}
