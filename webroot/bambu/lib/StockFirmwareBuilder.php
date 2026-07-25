<?php
declare(strict_types=1);

/**
 * Builds a local/private stock-hardware A1 mini research bundle.
 *
 * The only executable artifacts shipped at this stage are RAM-only Cortex-M4
 * probes that write a report into SRAM. They do not configure GPIO, PWM,
 * heaters, motors, flash, option bytes, or protection state.
 */
final class StockFirmwareBuilder {
    private const TEMPLATE_DIR = __DIR__.'/../templates/stock-a1';

    public static function status(PDO $db, array $profile): array {
        $isA1Mini=in_array(strtolower((string)($profile['printer_code']??'')),['n1','a1-mini','a1 mini'],true)
            || str_contains(strtolower((string)($profile['machine_name']??'')),'a1 mini');
        $pinEvidence=(int)$db->query("SELECT COUNT(*) FROM board_connectors WHERE printer_code='A1 mini' AND evidence_state IN ('measured','verified')")->fetchColumn();
        $hasBackup=false;
        $backupDir='';
        if(!empty($profile['id'])){
            $backupDir='profile-'.(int)$profile['id'];
        }
        return [
            'target_ok'=>$isA1Mini,
            'probe_ready'=>$isA1Mini,
            'commissioning_ready'=>$isA1Mini && $pinEvidence>=8,
            'persistent_ready'=>false,
            'pin_evidence_count'=>$pinEvidence,
            'has_backup'=>$hasBackup,
            'stages'=>[
                ['name'=>'RAM probe','state'=>$isA1Mini?'ready':'blocked','reason'=>$isA1Mini?'Prebuilt read-only SRAM probes are available.':'Select an A1 mini / n1 project.'],
                ['name'=>'Motor commissioning','state'=>($isA1Mini&&$pinEvidence>=8)?'ready':'blocked','reason'=>'Requires measured/verified board-to-MCU mappings and driver control evidence.'],
                ['name'=>'Thermal commissioning','state'=>'blocked','reason'=>'Requires thermistor curves, heater topology, GPIO, polarity, independent cutoff, and safe limits.'],
                ['name'=>'Persistent custom firmware','state'=>'blocked','reason'=>'Requires successful backups, recovery procedure, bootloader/application layout, complete pin map, and safety validation.'],
                ['name'=>'SD-card deployment','state'=>'blocked','reason'=>'Requires a locally controlled bootloader/update format; the vendor OTA remains signed and unchanged.'],
            ],
        ];
    }

    public static function generate(PDO $db, int $profileId, array $input, string $privateDir): string {
        if(($input['research_ack']??'')!=='yes') throw new RuntimeException('Confirm the stock-board research acknowledgement.');
        $profile=PrinterProfiles::get($db,$profileId);
        if(!$profile) throw new RuntimeException('Project not found.');
        $status=self::status($db,$profile);
        if(!$status['target_ok']) throw new RuntimeException('This builder is restricted to A1 mini / n1 stock-board projects.');
        if(!is_dir(self::TEMPLATE_DIR)) throw new RuntimeException('Stock A1 templates are missing.');

        $stamp=gmdate('Ymd-His');
        $base=rtrim($privateDir,'/').'/bambu/builds';
        $work=$base.'/a1-mini-stock-'.$profileId.'-'.$stamp;
        if(!is_dir($work) && !mkdir($work,0775,true) && !is_dir($work)) throw new RuntimeException('Could not create build directory.');
        self::copyTree(self::TEMPLATE_DIR,$work);

        $package=null;$components=[];
        if(!empty($profile['firmware_package_id'])){
            $q=$db->prepare('SELECT * FROM firmware_packages WHERE id=?');$q->execute([(int)$profile['firmware_package_id']]);$package=$q->fetch(PDO::FETCH_ASSOC)?:null;
            $q=$db->prepare('SELECT filename,role,board_revision,version,build_stamp,bytes,sha256,container_magic,container_version,declared_size FROM firmware_components WHERE package_id=? ORDER BY role,filename');
            $q->execute([(int)$profile['firmware_package_id']]);$components=$q->fetchAll(PDO::FETCH_ASSOC);
        }
        $hardware=$db->query("SELECT printer_code,board_role,board_revision,component_role,manufacturer,part_number,marking,package,attributes_json,evidence_state,source FROM hardware_components WHERE printer_code='A1 mini' ORDER BY board_role,component_role")->fetchAll(PDO::FETCH_ASSOC);
        $boards=$db->query("SELECT printer_code,board_role,board_revision,mcu,parameters_json,confidence,source FROM boards WHERE printer_code IN ('A1 mini','n1') ORDER BY board_role,board_revision")->fetchAll(PDO::FETCH_ASSOC);
        $evidence=PrinterProfiles::evidence($db,$profileId);

        $report=[
            'generated_at'=>gmdate('c'),
            'purpose'=>'Private local research and education',
            'target'=>[
                'printer'=>'Bambu Lab A1 mini',
                'platform'=>'n1',
                'main_motion_controller'=>'SPINTROL SPC2168APE80 (stock mainboard)',
                'toolhead_controller'=>'SPINTROL SPC1168APE48 (stock toolhead board)',
                'replacement_controller'=>false,
            ],
            'project'=>$profile,
            'parameter_evidence'=>$evidence,
            'source_ota'=>$package,
            'ota_components'=>$components,
            'hardware_evidence'=>$hardware,
            'board_evidence'=>$boards,
            'build_status'=>$status,
            'probe_contract'=>[
                'execution'=>'RAM only at 0x20000000',
                'report_address'=>'0x20003C00',
                'report_words'=>8,
                'writes'=>['SRAM report block only'],
                'peripheral_output_writes'=>0,
                'flash_writes'=>0,
                'protection_changes'=>0,
            ],
        ];
        file_put_contents($work.'/CAPCOM-PROJECT.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        file_put_contents($work.'/README-FIRST.txt',self::readme($profile,$status));
        file_put_contents($work.'/SOURCE-NOTES.txt',self::sourceNotes());
        if(!is_dir($work.'/commissioning')) mkdir($work.'/commissioning',0775,true);
        file_put_contents($work.'/commissioning/LOCKED.txt',self::commissioningLocked());
        file_put_contents($work.'/commissioning/PARAMETER-EXPERIMENT.csv',"timestamp,board,subsystem,parameter,commanded_value,units,measured_result,evidence_state,notes\n");

        self::hashTree($work,$work.'/SHA256SUMS.txt');
        $zipPath=$base.'/a1-mini-stock-firmware-lab-'.$profileId.'-'.$stamp.'.zip';
        self::zipTree($work,$zipPath);
        return $zipPath;
    }

    private static function readme(array $profile,array $status): string {
        $name=(string)($profile['profile_name']??'A1 mini project');
        return <<<TXT
HOTFETCHED CAPCOM — A1 MINI STOCK-HARDWARE FIRMWARE LAB
Project: {$name}

TARGET
- Stock A1 mini mainboard motion controller: SPINTROL SPC2168APE80
- Stock A1 mini toolhead controller: SPINTROL SPC1168APE48
- No SKR, STM32H7, TMC2209, Klipper, or replacement-controller assumptions

WHAT IS FUNCTIONAL NOW
The bundle contains prebuilt RAM-only Cortex-M4 probes for both controllers.
They execute from the first 16 KiB SRAM window and write an eight-word status
record to 0x20003C00. They do not configure GPIO, PWM, motors, heaters, fans,
flash, option/configuration words, or security state.

DO NOT FLASH THE PROBE INTO EMBEDDED FLASH.
Load it into SRAM through a verified SWD/J-Link connection. Stop if debug or
readout access is protected. Do not unlock, mass-erase, or alter protection.

WORKFLOW
1. Photograph and continuity-map the candidate debug pads.
2. Confirm GND, target 3.3 V reference, SWDIO, SWCLK, XRSTn and TRSTn.
3. Attempt a read-only stock flash backup. Stop if protected.
4. Load the matching probe_ram.hex into SRAM.
5. Run it and read eight words from 0x20003C00.
6. Decode the output with tools/decode_probe.py.
7. Record every result and measured pin in the worksheets.
8. Do not progress to motor or thermal commissioning until those stages are
   marked ready by evidence.

J-LINK NOTES
Use the lowest practical SWD clock (approximately 50–100 kHz initially), short
wires, common ground, and target-supplied VTref. Do not power the complete board
from the debugger. The included command files are templates because the exact
J-Link device selection and physical test-pad mapping still require validation.

CURRENT STAGE
RAM probe: {$status['stages'][0]['state']}
Motor commissioning: {$status['stages'][1]['state']}
Thermal commissioning: {$status['stages'][2]['state']}
Persistent firmware: {$status['stages'][3]['state']}
SD deployment: {$status['stages'][4]['state']}

Private research and education only. No public upload or automatic network use.
TXT;
    }

    private static function sourceNotes(): string {
        return <<<'TXT'
EVIDENCE BASIS

Official SPINTROL SPC2168 information:
- Dual ARM Cortex-M4 cores, up to 200 MHz
- Up to 512 KiB embedded flash and 80 KiB SRAM
- 20-channel 14-bit ADC, 16 PWM outputs, SWD/JTAG
- LQFP80 main debug pins: XRSTn pin 68, TRSTn pin 69,
  GPIO48/TCK/SWCK pin 70, GPIO49/TMS/SWD pin 71
- Flash execution base: 0x10000000
- SRAM base: 0x20000000
- UART ISP mode uses GPIO44 TX and GPIO45 RX on non-LQFP48 packages

Official SPINTROL SPC1168 information:
- Cortex-M4, 128 KiB flash, 64 KiB SRAM for the observed APE48 part family
- LQFP48 debug pins: GPIO38/TMS/SWD pin 44,
  GPIO39/TCK/SWCK pin 45, TRSTn pin 46, XRSTn pin 47, BOOT pin 48
- Flash base: 0x10000000; SRAM base: 0x20000000

Community motherboard teardown observations are stored as observed—not official:
- ESP32-S3 application processor with GigaDevice 25Q128ESIG 16 MiB QSPI flash
- SPC2168 auxiliary/motion controller
- 75176E differential transceiver
- Six unidentified devices under the heatsink, plus analog and power circuitry

No community observation is automatically promoted to a verified electrical
pin map or a firmware-generation constant.
TXT;
    }

    private static function commissioningLocked(): string {
        return <<<'TXT'
COMMISSIONING OUTPUTS ARE LOCKED

HotFetched will not generate active motor, heater, fan, or persistent-flash
firmware until the following are measured or verified for the actual board:

- MCU-to-PCB test-pad mapping
- Driver/H-bridge identity and control topology
- Motor phase ordering and safe current-control transfer function
- Current-sense resistor/amplifier path
- Axis pulse/PWM generation method and feedback signals
- Heater MOSFET gate, polarity, voltage/current limits and independent cutoff
- Thermistor model, divider, ADC channel and coefficients
- Fan outputs, voltages, polarity and tachometer behavior
- Endstop/probe/load-cell interfaces and polarity
- Toolhead communication physical layer and protocol
- Original flash backup and tested recovery procedure
- Bootloader/application/NVR allocation

Low guessed values do not make an unidentified GPIO safe. A wrong output can
still energize a heater or short a motor phase.
TXT;
    }

    private static function copyTree(string $src,string $dst):void{
        if(!is_dir($dst))mkdir($dst,0775,true);
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $item){$rel=substr($item->getPathname(),strlen($src)+1);$target=$dst.'/'.$rel;if($item->isDir()){if(!is_dir($target))mkdir($target,0775,true);}else copy($item->getPathname(),$target);}
    }
    private static function hashTree(string $root,string $out):void{
        $rows=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach($it as $item){if(!$item->isFile()||$item->getPathname()===$out)continue;$rel=substr($item->getPathname(),strlen($root)+1);$rows[]=hash_file('sha256',$item->getPathname()).'  '.$rel;}
        sort($rows);file_put_contents($out,implode("\n",$rows)."\n");
    }
    private static function zipTree(string $root,string $zipPath):void{
        $zip=new ZipArchive();if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create build ZIP.');
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach($it as $item){if($item->isFile()){$rel=substr($item->getPathname(),strlen($root)+1);$zip->addFile($item->getPathname(),$rel);}}
        $zip->close();
    }
}
