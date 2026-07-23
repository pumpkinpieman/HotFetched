<?php
declare(strict_types=1);

/**
 * Generates a conservative, motion-only Klipper commissioning bundle for an
 * A1 mini mechanical conversion using a BTT SKR 3 / SKR 3 EZ and TMC2209s.
 *
 * It deliberately does NOT generate Bambu-signed firmware, heater sections,
 * bed-heater sections, probe configuration, or automatic homing.
 */
final class OpenControllerBuilder {
    private const TARGET = 'BTT SKR 3 / SKR 3 EZ + TMC2209';
    private const AXIS_PINS = [
        'x' => ['step'=>'PD4',  'dir'=>'PD3',  'enable'=>'!PD6', 'uart'=>'PD5', 'endstop'=>'^PC1'],
        'y' => ['step'=>'PA15', 'dir'=>'PA8',  'enable'=>'!PD1', 'uart'=>'PD0', 'endstop'=>'^PC3'],
        'z' => ['step'=>'PE2',  'dir'=>'PE3',  'enable'=>'!PE0', 'uart'=>'PE1', 'endstop'=>'^PC0'],
        'e0'=> ['step'=>'PD15', 'dir'=>'PD14', 'enable'=>'!PC7', 'uart'=>'PC6', 'endstop'=>''],
    ];

    public static function defaults(array $profile): array {
        return [
            'steps_x' => self::positiveOr($profile['steps_x']??null,20.0),
            'steps_y' => self::positiveOr($profile['steps_y']??null,20.0),
            'steps_z' => self::positiveOr($profile['steps_z']??null,50.0),
            'steps_e0'=> self::positiveOr($profile['steps_e0']??null,10.0),
            'direction_x' => self::directionOr($profile['direction_x']??null,'normal'),
            'direction_y' => self::directionOr($profile['direction_y']??null,'normal'),
            'direction_z' => self::directionOr($profile['direction_z']??null,'normal'),
            'direction_e0'=> self::directionOr($profile['direction_e0']??null,'normal'),
            'current_x' => self::positiveOr($profile['current_x']??null,200.0),
            'current_y' => self::positiveOr($profile['current_y']??null,200.0),
            'current_z' => self::positiveOr($profile['current_z']??null,200.0),
            'current_e0'=> self::positiveOr($profile['current_e0']??null,150.0),
            'microsteps'=> self::positiveOr($profile['microsteps']??null,16),
            'full_steps_per_rotation'=>200,
            'max_velocity'=>10.0,
            'max_accel'=>50.0,
            'max_z_velocity'=>1.0,
            'max_z_accel'=>5.0,
            'homing_speed'=>2.0,
            'test_distance'=>0.5,
        ];
    }

    public static function generate(PDO $db,int $profileId,array $input,string $private): string {
        if(($input['experimental_ack']??'')!=='yes') {
            throw new InvalidArgumentException('Experimental controller acknowledgement is required.');
        }
        $profile=PrinterProfiles::get($db,$profileId);
        if(!$profile) throw new RuntimeException('Select and save an A1 mini project first.');
        if(!str_contains(strtolower((string)$profile['printer_code']),'a1 mini') && strtolower((string)$profile['printer_code'])!=='n1') {
            throw new RuntimeException('This first open-controller target is restricted to A1 mini / n1 projects.');
        }

        $d=self::validatedInput(array_merge(self::defaults($profile),$input));
        $stamp=gmdate('Ymd-His');
        $base=rtrim($private,'/').'/capcom/builds';
        if(!is_dir($base) && !mkdir($base,0770,true) && !is_dir($base)) throw new RuntimeException('Could not create CAPCOM build directory.');
        $work=$base.'/a1mini-skr3-'.$profileId.'-'.$stamp;
        if(!mkdir($work,0770,true) && !is_dir($work)) throw new RuntimeException('Could not create build workspace.');

        $rotation=[];
        foreach(['x','y','z','e0'] as $axis) {
            $rotation[$axis]=($d['full_steps_per_rotation']*$d['microsteps'])/$d['steps_'.$axis];
        }

        file_put_contents($work.'/printer.cfg',self::printerCfg($profile,$d,$rotation));
        file_put_contents($work.'/commissioning.cfg',self::commissioningCfg($d));
        file_put_contents($work.'/README-FIRST.txt',self::readme($profile,$d,$rotation));
        file_put_contents($work.'/SKR3-MENUCONFIG.txt',self::menuconfigGuide());
        file_put_contents($work.'/build-klipper-mcu.sh',self::buildScript());
        @chmod($work.'/build-klipper-mcu.sh',0750);
        file_put_contents($work.'/commissioning-log.csv',"timestamp,axis,test,commanded_mm,observed_mm,direction,current_mA,microsteps,result,notes\n");
        $report=[
            'created_at'=>gmdate('c'),
            'purpose'=>'Private local research and education',
            'project'=>['id'=>$profileId,'printer_code'=>$profile['printer_code'],'profile_name'=>$profile['profile_name']],
            'target'=>self::TARGET,
            'output_kind'=>'Klipper motion-only commissioning bundle',
            'not_included'=>['Bambu signed OTA','Bambu bootloader','heater control','bed heater control','probe control','automatic homing','stock display/AMS/cloud support'],
            'experimental_parameters'=>$d,
            'derived_rotation_distance_mm_per_motor_rev'=>$rotation,
            'safety_state'=>[
                'heaters_configured'=>false,
                'bed_heater_configured'=>false,
                'automatic_homing_enabled'=>false,
                'motion_limits'=>'commissioning-low',
                'evidence_status'=>'experimental placeholders unless profile evidence says otherwise',
            ],
            'source_pin_map'=>'Klipper generic BigTreeTech SKR 3 configuration; target-controller pins only',
        ];
        file_put_contents($work.'/BUILD-REPORT.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

        $zip=$base.'/a1-mini-open-controller-'.$profileId.'-'.$stamp.'.zip';
        self::zipDirectory($work,$zip);
        return $zip;
    }

    private static function validatedInput(array $in): array {
        $out=[];
        foreach(['steps_x','steps_y','steps_z','steps_e0'] as $f) $out[$f]=self::range($in,$f,0.1,10000);
        foreach(['current_x','current_y','current_z','current_e0'] as $f) $out[$f]=self::range($in,$f,50,1200);
        $out['microsteps']=(int)self::range($in,'microsteps',1,256);
        if(($out['microsteps'] & ($out['microsteps']-1))!==0) throw new InvalidArgumentException('Microsteps must be a power of two.');
        $out['full_steps_per_rotation']=(int)self::range($in,'full_steps_per_rotation',200,400);
        foreach(['direction_x','direction_y','direction_z','direction_e0'] as $f) {
            $v=(string)($in[$f]??'normal');
            if(!in_array($v,['normal','inverted'],true)) throw new InvalidArgumentException("$f must be normal or inverted for the experimental output.");
            $out[$f]=$v;
        }
        $out['max_velocity']=self::range($in,'max_velocity',0.1,50);
        $out['max_accel']=self::range($in,'max_accel',1,500);
        $out['max_z_velocity']=self::range($in,'max_z_velocity',0.1,10);
        $out['max_z_accel']=self::range($in,'max_z_accel',0.5,100);
        $out['homing_speed']=self::range($in,'homing_speed',0.5,10);
        $out['test_distance']=self::range($in,'test_distance',0.05,2);
        return $out;
    }

    private static function range(array $in,string $field,float $min,float $max): float {
        $v=$in[$field]??null;
        if(!is_numeric($v)) throw new InvalidArgumentException("$field must be numeric.");
        $n=(float)$v;
        if($n<$min || $n>$max) throw new InvalidArgumentException("$field must be between $min and $max.");
        return $n;
    }
    private static function positiveOr(mixed $value,float|int $fallback): float|int {return is_numeric($value)&&(float)$value>0?0+$value:$fallback;}
    private static function directionOr(mixed $value,string $fallback): string {return in_array($value,['normal','inverted'],true)?(string)$value:$fallback;}
    private static function dirPin(string $axis,array $d): string {return ($d['direction_'.$axis]==='inverted'?'!':'').self::AXIS_PINS[$axis]['dir'];}
    private static function f(float $n,int $places=6): string {return rtrim(rtrim(number_format($n,$places,'.',''),'0'),'.');}

    private static function printerCfg(array $p,array $d,array $r): string {
        $xmax=(float)($p['x_travel']?:180);
        $ymax=(float)($p['y_travel']?:180);
        $zmax=(float)($p['z_travel']?:180);
        $dirX=self::dirPin('x',$d); $dirY=self::dirPin('y',$d); $dirZ=self::dirPin('z',$d); $dirE=self::dirPin('e0',$d);
        $rx=self::f($r['x']); $ry=self::f($r['y']); $rz=self::f($r['z']); $re=self::f($r['e0']);
        $cx=self::f($d['current_x']/1000,3); $cy=self::f($d['current_y']/1000,3); $cz=self::f($d['current_z']/1000,3); $ce=self::f($d['current_e0']/1000,3);
        return <<<CFG
# Generated by HotFetched CAPCOM — A1 mini open-controller commissioning target
# PRIVATE LOCAL RESEARCH / EDUCATION ONLY
# Target: BTT SKR 3 / SKR 3 EZ + TMC2209
# This is NOT Bambu firmware and cannot be installed on the stock A1 mini board.
# HEATERS, BED HEATER, PROBE, AUTOMATIC HOMING, DISPLAY, AMS, AND CLOUD ARE DISABLED.

[include commissioning.cfg]

[mcu]
# Replace after flashing and reconnecting the SKR 3:
serial: /dev/serial/by-id/usb-Klipper_Klipper_firmware_REPLACE_ME-if00

[printer]
kinematics: cartesian
max_velocity: {$d['max_velocity']}
max_accel: {$d['max_accel']}
max_z_velocity: {$d['max_z_velocity']}
max_z_accel: {$d['max_z_accel']}
square_corner_velocity: 1

[idle_timeout]
timeout: 30

[stepper_x]
step_pin: PD4
dir_pin: {$dirX}
enable_pin: !PD6
microsteps: {$d['microsteps']}
full_steps_per_rotation: {$d['full_steps_per_rotation']}
rotation_distance: {$rx}
endstop_pin: ^PC1
position_endstop: 0
position_min: -1
position_max: {$xmax}
homing_speed: {$d['homing_speed']}
homing_retract_dist: 0

[tmc2209 stepper_x]
uart_pin: PD5
run_current: {$cx}
interpolate: False
stealthchop_threshold: 0

[stepper_y]
step_pin: PA15
dir_pin: {$dirY}
enable_pin: !PD1
microsteps: {$d['microsteps']}
full_steps_per_rotation: {$d['full_steps_per_rotation']}
rotation_distance: {$ry}
endstop_pin: ^PC3
position_endstop: 0
position_min: -1
position_max: {$ymax}
homing_speed: {$d['homing_speed']}
homing_retract_dist: 0

[tmc2209 stepper_y]
uart_pin: PD0
run_current: {$cy}
interpolate: False
stealthchop_threshold: 0

[stepper_z]
step_pin: PE2
dir_pin: {$dirZ}
enable_pin: !PE0
microsteps: {$d['microsteps']}
full_steps_per_rotation: {$d['full_steps_per_rotation']}
rotation_distance: {$rz}
endstop_pin: ^PC0
position_endstop: 0
position_min: -1
position_max: {$zmax}
homing_speed: {$d['homing_speed']}
homing_retract_dist: 0

[tmc2209 stepper_z]
uart_pin: PE1
run_current: {$cz}
interpolate: False
stealthchop_threshold: 0

[manual_stepper extruder_test]
step_pin: PD15
dir_pin: {$dirE}
enable_pin: !PC7
microsteps: {$d['microsteps']}
full_steps_per_rotation: {$d['full_steps_per_rotation']}
rotation_distance: {$re}
velocity: 1
accel: 5

[tmc2209 manual_stepper extruder_test]
uart_pin: PC6
run_current: {$ce}
interpolate: False
stealthchop_threshold: 0

[respond]
CFG;
    }

    private static function commissioningCfg(array $d): string {
        $test=self::f($d['test_distance'],3);
        return <<<CFG
# Safety locks and commissioning helpers.
# Remove/replace this file only after endstops, travel direction, and scaling are measured.

[homing_override]
axes: xyz
gcode:
  { action_raise_error("CAPCOM commissioning lock: G28 is disabled until endstops and directions are physically verified.") }

[gcode_macro A1_TEST_X]
description: Low-distance X test; keep a hand on emergency power
gcode:
  STEPPER_BUZZ STEPPER=stepper_x

[gcode_macro A1_TEST_Y]
description: Low-distance Y test; keep a hand on emergency power
gcode:
  STEPPER_BUZZ STEPPER=stepper_y

[gcode_macro A1_TEST_Z]
description: Low-distance Z test; support the gantry/bed as applicable
gcode:
  STEPPER_BUZZ STEPPER=stepper_z

[gcode_macro A1_TEST_E]
description: Unheated extruder motor test only
gcode:
  MANUAL_STEPPER STEPPER=extruder_test ENABLE=1
  MANUAL_STEPPER STEPPER=extruder_test MOVE={$test} SPEED=0.5 ACCEL=2
  MANUAL_STEPPER STEPPER=extruder_test ENABLE=0

[gcode_macro A1_MOTORS_OFF]
gcode:
  M84
  MANUAL_STEPPER STEPPER=extruder_test ENABLE=0
CFG;
    }

    private static function readme(array $p,array $d,array $r): string {
        $project=$p['printer_code'].' — '.$p['profile_name'];
        return <<<TXT
HOTFETCHED CAPCOM — A1 MINI OPEN-CONTROLLER COMMISSIONING BUNDLE
================================================================
Project: {$project}
Target: BTT SKR 3 / SKR 3 EZ with TMC2209 drivers
Purpose: private local research and education

THIS IS NOT STOCK BAMBU FIRMWARE.
It does not run on the original Bambu mainboard, recreate a signed BIMH OTA,
or preserve the stock display, calibration, AMS, cloud, toolhead protocol, or
active motor-noise-cancellation features.

INITIAL SAFETY STATE
--------------------
- Motor supply: use 24 V; do not use the SKR 3 high-voltage rail with TMC2209
- Hotend heater: NOT CONFIGURED
- Bed heater: NOT CONFIGURED
- Thermistors: NOT CONFIGURED
- Fans: NOT CONFIGURED
- Probe: NOT CONFIGURED
- Automatic homing: BLOCKED
- Motion speed/acceleration: deliberately low
- Driver currents and scaling: experimental placeholders

EXPERIMENTAL VALUES USED
------------------------
X steps/mm: {$d['steps_x']}     rotation_distance: {$r['x']}
Y steps/mm: {$d['steps_y']}     rotation_distance: {$r['y']}
Z steps/mm: {$d['steps_z']}     rotation_distance: {$r['z']}
E steps/mm: {$d['steps_e0']}    rotation_distance: {$r['e0']}
Microsteps: {$d['microsteps']}
Full steps/revolution assumption: {$d['full_steps_per_rotation']}
X/Y/Z/E currents: {$d['current_x']} / {$d['current_y']} / {$d['current_z']} / {$d['current_e0']} mA
Maximum XY velocity: {$d['max_velocity']} mm/s
Maximum acceleration: {$d['max_accel']} mm/s^2
Maximum Z velocity: {$d['max_z_velocity']} mm/s
Extruder test distance setting: {$d['test_distance']} mm
Axis STEPPER_BUZZ diagnostic: fixed 1 mm out-and-back, repeated by Klipper

COMMISSIONING ORDER
-------------------
1. Disconnect all heater outputs and the bed-heater output physically.
2. Do not connect an unknown thermistor to a heater-enabled configuration.
3. Fit one TMC2209 and connect one motor only.
4. Confirm motor coil pairs with a meter before connecting the driver.
5. Flash the SKR 3 using locally supplied Klipper source and the settings in
   SKR3-MENUCONFIG.txt.
6. Update the [mcu] serial path in printer.cfg.
7. Run FIRMWARE_RESTART.
8. Run DUMP_TMC STEPPER=stepper_x (or the axis under test).
9. Keep clear of the mechanism and keep immediate access to power removal.
10. Run A1_TEST_X, A1_TEST_Y, or A1_TEST_Z one axis at a time.
11. Measure actual displacement and record it in commissioning-log.csv.
12. If direction is wrong, change that axis dir_pin polarity by adding/removing !.
13. If the motor only chatters, stop; verify coil pairing before raising current.
14. Raise current in small increments only while monitoring motor and driver heat.
15. Do not enable G28 until physical endstops/probe behavior and polarity are known.
16. Do not add heaters until sensor type, pull-up, heater resistance/wattage,
    MOSFET wiring, thermal fuse, and runaway behavior are independently verified.

SCALING UPDATE
--------------
After commanding a small movement:

new_steps_per_mm = old_steps_per_mm * commanded_distance / measured_distance

Enter the measured result into HotFetched, regenerate this bundle, and repeat
with progressively longer but still constrained tests.

EXPECTED FAILURE MODES AT LOW VALUES
------------------------------------
- Motor does not move: current too low, wrong coil pairs, disabled driver, or UART issue.
- Motor moves less than commanded: configured steps/mm is below the real value.
- Motor moves opposite: direction polarity is wrong.
- TMC UART error: driver mode/jumper/UART pin or driver installation is wrong.

Never use this commissioning profile to print. It is a motion-characterization
profile designed to prevent heater activation and automatic homing.
TXT;
    }

    private static function menuconfigGuide(): string {
        return <<<TXT
KLIPPER MCU BUILD SETTINGS FOR BTT SKR 3 / SKR 3 EZ
===================================================
Use a current local Klipper source tree.

make menuconfig

- Enable extra low-level configuration options
- Micro-controller Architecture: STMicroelectronics STM32
- Processor model: STM32H743 OR STM32H723 (read the marking on your board)
- Bootloader offset: 128KiB bootloader
- Clock Reference: 25 MHz crystal
- Communication interface: USB (on PA11/PA12)

Build with: make
Copy out/klipper.bin to a FAT32 SD card as firmware.bin and reboot the SKR 3.
After a successful flash, the board commonly renames the file to FIRMWARE.CUR.
Use the exact processor actually fitted to the board.
TXT;
    }

    private static function buildScript(): string {
        return <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
KLIPPER_SRC="${KLIPPER_SRC:-/opt/klipper}"
OUT_DIR="${OUT_DIR:-$(pwd)/compiled}"
if [[ ! -f "$KLIPPER_SRC/Makefile" ]]; then
  echo "Klipper source not found at $KLIPPER_SRC" >&2
  echo "Mount or copy a local Klipper source tree and set KLIPPER_SRC." >&2
  exit 2
fi
mkdir -p "$OUT_DIR"
cd "$KLIPPER_SRC"
echo "Configure for SKR 3: STM32H743/H723, 128KiB bootloader, 25MHz crystal, USB."
make menuconfig
make -j"$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2)"
cp -f out/klipper.bin "$OUT_DIR/firmware.bin"
sha256sum "$OUT_DIR/firmware.bin" > "$OUT_DIR/SHA256SUMS.txt"
echo "Built $OUT_DIR/firmware.bin"
SH;
    }

    private static function zipDirectory(string $dir,string $zipPath): void {
        if(class_exists('ZipArchive')) {
            $zip=new ZipArchive();
            if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Could not create output ZIP.');
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
            foreach($it as $file) $zip->addFile($file->getPathname(),substr($file->getPathname(),strlen($dir)+1));
            $zip->close(); return;
        }
        $zipBin=trim((string)shell_exec('command -v zip 2>/dev/null'));
        if($zipBin==='') throw new RuntimeException('ZipArchive or the zip command is required to create the bundle.');
        $cmd='cd '.escapeshellarg($dir).' && '.escapeshellarg($zipBin).' -q -r '.escapeshellarg($zipPath).' .';
        exec($cmd,$o,$code); if($code!==0 || !is_file($zipPath)) throw new RuntimeException('Could not package output ZIP.');
    }
}
