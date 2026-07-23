<?php
declare(strict_types=1);
final class ResearchDb {
    public static function open(string $privateDir): PDO {
        $dir = rtrim($privateDir, '/') . '/bambu';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create data directory');
        $pdo = new PDO('sqlite:' . $dir . '/research.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
        self::migrate($pdo);
        return $pdo;
    }
    private static function addColumn(PDO $db,string $table,string $column,string $ddl): void {
        $cols=$db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
        if(!in_array($column,array_column($cols,'name'),true)) $db->exec("ALTER TABLE $table ADD COLUMN $ddl");
    }
    private static function migrate(PDO $db): void {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS firmware_packages (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 sha256 TEXT NOT NULL UNIQUE,
 original_name TEXT NOT NULL,
 stored_name TEXT NOT NULL,
 printer_code TEXT,
 version TEXT,
 build_stamp TEXT,
 bytes INTEGER NOT NULL,
 imported_at TEXT NOT NULL,
 notes TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS firmware_components (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 package_id INTEGER NOT NULL REFERENCES firmware_packages(id) ON DELETE CASCADE,
 filename TEXT NOT NULL,
 kind TEXT NOT NULL,
 role TEXT,
 board_revision TEXT,
 version TEXT,
 build_stamp TEXT,
 bytes INTEGER NOT NULL,
 sha256 TEXT NOT NULL,
 container_magic TEXT,
 container_version INTEGER,
 declared_size INTEGER,
 embedded_names_json TEXT NOT NULL DEFAULT '[]',
 manifest_json TEXT,
 UNIQUE(package_id, filename)
);
CREATE TABLE IF NOT EXISTS boards (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 printer_code TEXT NOT NULL,
 board_role TEXT NOT NULL,
 board_revision TEXT NOT NULL DEFAULT '',
 mcu TEXT NOT NULL DEFAULT '',
 parameters_json TEXT NOT NULL DEFAULT '{}',
 confidence TEXT NOT NULL DEFAULT 'observed',
 source TEXT NOT NULL DEFAULT '',
 updated_at TEXT NOT NULL,
 UNIQUE(printer_code, board_role, board_revision)
);
CREATE TABLE IF NOT EXISTS printer_profiles (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 printer_code TEXT NOT NULL,
 profile_name TEXT NOT NULL,
 machine_name TEXT NOT NULL DEFAULT '',
 kinematics TEXT NOT NULL DEFAULT 'Unknown',
 connectivity TEXT NOT NULL DEFAULT '',
 x_travel REAL, y_travel REAL, z_travel REAL,
 max_speed_xy REAL, max_acceleration REAL,
 steps_x REAL, steps_y REAL, steps_z REAL, steps_z2 REAL,
 steps_e0 REAL, steps_e1 REAL, steps_e2 REAL, steps_e3 REAL,
 invert_x INTEGER NOT NULL DEFAULT 0, invert_y INTEGER NOT NULL DEFAULT 0,
 invert_z INTEGER NOT NULL DEFAULT 0, invert_z2 INTEGER NOT NULL DEFAULT 0,
 invert_e0 INTEGER NOT NULL DEFAULT 0, invert_e1 INTEGER NOT NULL DEFAULT 0,
 invert_e2 INTEGER NOT NULL DEFAULT 0, invert_e3 INTEGER NOT NULL DEFAULT 0,
 current_x REAL, current_y REAL, current_z REAL, current_z2 REAL,
 current_e0 REAL, current_e1 REAL, current_e2 REAL, current_e3 REAL,
 microsteps INTEGER,
 hotend_max_temp REAL, bed_max_temp REAL, chamber_max_temp REAL,
 bed_probe TEXT NOT NULL DEFAULT '', nozzle_diameter REAL, extruder_count INTEGER,
 notes TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT 'manual',
 confidence TEXT NOT NULL DEFAULT 'unknown', created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS parameter_evidence (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 profile_id INTEGER NOT NULL REFERENCES printer_profiles(id) ON DELETE CASCADE,
 field_name TEXT NOT NULL,
 evidence_state TEXT NOT NULL DEFAULT 'unknown',
 source TEXT NOT NULL DEFAULT '',
 detail TEXT NOT NULL DEFAULT '',
 firmware_component_id INTEGER REFERENCES firmware_components(id),
 binary_offset TEXT,
 updated_at TEXT NOT NULL,
 UNIQUE(profile_id,field_name)
);
CREATE TABLE IF NOT EXISTS hardware_components (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 printer_code TEXT NOT NULL,
 board_role TEXT NOT NULL,
 board_revision TEXT NOT NULL DEFAULT '',
 component_role TEXT NOT NULL,
 manufacturer TEXT NOT NULL DEFAULT '',
 part_number TEXT NOT NULL DEFAULT '',
 marking TEXT NOT NULL DEFAULT '',
 package TEXT NOT NULL DEFAULT '',
 attributes_json TEXT NOT NULL DEFAULT '{}',
 evidence_state TEXT NOT NULL DEFAULT 'observed',
 source TEXT NOT NULL DEFAULT '',
 updated_at TEXT NOT NULL,
 UNIQUE(printer_code,board_role,board_revision,component_role,part_number,marking)
);
CREATE TABLE IF NOT EXISTS board_connectors (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 printer_code TEXT NOT NULL,
 board_role TEXT NOT NULL,
 board_revision TEXT NOT NULL DEFAULT '',
 connector_name TEXT NOT NULL,
 pin_count INTEGER,
 function TEXT NOT NULL DEFAULT '',
 voltage TEXT NOT NULL DEFAULT '',
 bus TEXT NOT NULL DEFAULT '',
 pinout_json TEXT NOT NULL DEFAULT '{}',
 evidence_state TEXT NOT NULL DEFAULT 'unknown',
 source TEXT NOT NULL DEFAULT '',
 updated_at TEXT NOT NULL,
 UNIQUE(printer_code,board_role,board_revision,connector_name)
);
CREATE INDEX IF NOT EXISTS idx_profiles_printer ON printer_profiles(printer_code);
CREATE INDEX IF NOT EXISTS idx_components_role ON firmware_components(role);
CREATE INDEX IF NOT EXISTS idx_components_version ON firmware_components(version);
CREATE INDEX IF NOT EXISTS idx_evidence_profile ON parameter_evidence(profile_id);
CREATE INDEX IF NOT EXISTS idx_hw_printer ON hardware_components(printer_code,board_role);
SQL);
        self::addColumn($db,'firmware_components','manifest_json','manifest_json TEXT');
        self::addColumn($db,'printer_profiles','firmware_package_id','firmware_package_id INTEGER REFERENCES firmware_packages(id)');
        foreach(['x','y','z','z2','e0','e1','e2','e3'] as $axis) self::addColumn($db,'printer_profiles','direction_'.$axis,"direction_$axis TEXT NOT NULL DEFAULT 'unknown'");
        if((int)$db->query('SELECT COUNT(*) FROM boards')->fetchColumn()===0) self::seedBoards($db);
        self::seedA1Hardware($db);
    }
    private static function seedBoards(PDO $db): void {
        $now=gmdate('c');
        $rows=[
          ['P1-series','MC','','SPINTROL SPC2168APE80',['motor_drivers'=>'AT8236','axes'=>['A','B','Z'],'connects_to'=>['AP','TH','AMS interface','AC board']],'documented','OpenBL public hardware notes'],
          ['P1-series','TH','','SPINTROL SPC1168APE48',['motor_driver'=>'AT8236','controls'=>['extruder','hotend interface','fans','thermistor']],'documented','OpenBL public hardware notes'],
          ['P1-series','AP','','unknown',['interfaces'=>['USB','camera FPC','LED','SD card','antenna']],'documented','OpenBL public hardware notes'],
        ];
        $s=$db->prepare('INSERT OR IGNORE INTO boards(printer_code,board_role,board_revision,mcu,parameters_json,confidence,source,updated_at) VALUES(?,?,?,?,?,?,?,?)');
        foreach($rows as $r)$s->execute([$r[0],$r[1],$r[2],$r[3],json_encode($r[4],JSON_UNESCAPED_SLASHES),$r[5],$r[6],$now]);
    }
    private static function seedA1Hardware(PDO $db): void {
        $now=gmdate('c');
        $boards=[
          ['A1 mini','TH','N1_EXTRU_V5','SPINTROL SPC1168APE48',[
              'pcb_marking'=>'N1_EXTRU_V5 / B00203-02',
              'clock_observed'=>'24 MHz',
              'motor_driver_observed'=>'AT8236',
              'flash_base'=>'0x10000000','flash_bytes'=>131072,
              'sram_base'=>'0x20000000','sram_bytes'=>65536,
              'debug'=>['SWDIO'=>'pin 44 GPIO38','SWCLK'=>'pin 45 GPIO39','TRSTn'=>'pin 46','XRSTn'=>'pin 47','BOOT'=>'pin 48 GPIO40']
          ],'documented','User board photographs + SPINTROL SPC1168 datasheet'],
          ['A1 mini','MC','rev2','SPINTROL SPC2168APE80',[
              'firmware_role'=>'MC','ota_board_revision'=>'rev2','board_family'=>'N1',
              'cores'=>'2x ARM Cortex-M4','max_clock_mhz'=>200,
              'flash_base'=>'0x10000000','flash_bytes'=>524288,
              'sram_base'=>'0x20000000','sram_bytes'=>81920,
              'adc'=>'20-channel 14-bit','pwm_outputs'=>16,
              'debug'=>['XRSTn'=>'pin 68','TRSTn'=>'pin 69','SWCLK'=>'pin 70 GPIO48','SWDIO'=>'pin 71 GPIO49'],
              'uart_isp'=>['BOOT'=>'pin 67 GPIO47','TX'=>'pin 64 GPIO44','RX'=>'pin 65 GPIO45']
          ],'documented','User mainboard photographs + imported N1 OTA + official SPINTROL SPC2168 product page/datasheet'],
          ['A1 mini','AP','community-observed','ESP32-S3',[
              'external_flash'=>'GigaDevice 25Q128ESIG, reported 16 MiB',
              'role'=>'user interface and communications (community inference)'
          ],'observed','Community teardown; not an official schematic'],
        ];
        $bs=$db->prepare('INSERT INTO boards(printer_code,board_role,board_revision,mcu,parameters_json,confidence,source,updated_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(printer_code,board_role,board_revision) DO UPDATE SET mcu=excluded.mcu,parameters_json=excluded.parameters_json,confidence=excluded.confidence,source=excluded.source,updated_at=excluded.updated_at');
        foreach($boards as $r)$bs->execute([$r[0],$r[1],$r[2],$r[3],json_encode($r[4],JSON_UNESCAPED_SLASHES),$r[5],$r[6],$now]);

        $parts=[
          ['A1 mini','TH','N1_EXTRU_V5','Toolhead MCU','SPINTROL','SPC1168APE48','SPC1168APE','LQFP48',json_encode(['clock_observed'=>'24 MHz','flash_bytes'=>131072,'sram_bytes'=>65536,'architecture'=>'ARM Cortex-M4'],JSON_UNESCAPED_SLASHES),'documented','Visible package/PCB marking in user photos + official SPC1168 datasheet'],
          ['A1 mini','TH','N1_EXTRU_V5','Extruder motor driver','','AT8236','AT8236','',json_encode([],JSON_UNESCAPED_SLASHES),'observed','Visible package marking in user-provided toolhead photos'],
          ['A1 mini','MC','rev2','Motion-control MCU','SPINTROL','SPC2168APE80','SPC2168APE','LQFP80',json_encode(['cores'=>2,'max_clock_mhz'=>200,'flash_bytes'=>524288,'sram_bytes'=>81920,'adc_bits'=>14,'adc_channels'=>20,'pwm_outputs'=>16],JSON_UNESCAPED_SLASHES),'documented','Visible package marking + official SPINTROL product specification'],
          ['A1 mini','AP','community-observed','Application processor','Espressif','ESP32-S3','ESP32-S3','',json_encode(['role'=>'UI and communications, inferred by teardown author'],JSON_UNESCAPED_SLASHES),'observed','Community motherboard teardown; requires independent board confirmation'],
          ['A1 mini','AP','community-observed','External QSPI flash','GigaDevice','25Q128ESIG','25Q128ESIG','',json_encode(['reported_capacity_mib'=>16],JSON_UNESCAPED_SLASHES),'observed','Community motherboard teardown; requires independent board confirmation'],
          ['A1 mini','MC','community-observed','Differential transceiver','','75176E','75176E','',json_encode(['reported_role'=>'RS-485 transceiver'],JSON_UNESCAPED_SLASHES),'observed','Community motherboard teardown; requires continuity/protocol confirmation'],
          ['A1 mini','MC','community-observed','Motor power/control devices','','Unknown','6x under heatsink','',json_encode(['candidate_roles'=>['H-bridge','stepper power stage','current sensing'],'verified'=>false],JSON_UNESCAPED_SLASHES),'observed','Community teardown observation; identities remain unknown'],
          ['A1 mini','MC','community-observed','Dual operational amplifiers','','AS358A','2x AS358A','',json_encode([],JSON_UNESCAPED_SLASHES),'observed','Community motherboard teardown'],
          ['A1 mini','MC','community-observed','MOSFET driver','','EG2104','EG2104','',json_encode([],JSON_UNESCAPED_SLASHES),'observed','Community motherboard teardown'],
          ['A1 mini','MC','community-observed','Current-sense amplifier','','GW 2ZD3X','GW 2ZD3X','',json_encode([],JSON_UNESCAPED_SLASHES),'observed','Community motherboard teardown; exact manufacturer/function requires confirmation'],
        ];
        $ps=$db->prepare('INSERT INTO hardware_components(printer_code,board_role,board_revision,component_role,manufacturer,part_number,marking,package,attributes_json,evidence_state,source,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(printer_code,board_role,board_revision,component_role,part_number,marking) DO UPDATE SET manufacturer=excluded.manufacturer,package=excluded.package,attributes_json=excluded.attributes_json,evidence_state=excluded.evidence_state,source=excluded.source,updated_at=excluded.updated_at');
        foreach($parts as $r)$ps->execute(array_merge($r,[$now]));

        $connectors=[
          ['A1 mini','MC','rev2','SPC2168 package debug/ISP signals',8,'MCU package signals; PCB test-pad mapping not yet known','3.3 V logic','SWD/JTAG/UART ISP',[
              'XRSTn'=>['pin'=>68],'TRSTn'=>['pin'=>69],'SWCLK'=>['pin'=>70,'gpio'=>'GPIO48'],'SWDIO'=>['pin'=>71,'gpio'=>'GPIO49'],
              'BOOT'=>['pin'=>67,'gpio'=>'GPIO47'],'ISP_TX'=>['pin'=>64,'gpio'=>'GPIO44'],'ISP_RX'=>['pin'=>65,'gpio'=>'GPIO45'],'GND'=>['pin'=>'DVSS multiple']
          ],'documented','Official SPC2168 LQFP80 datasheet; not yet mapped to PCB pads'],
          ['A1 mini','TH','N1_EXTRU_V5','SPC1168 package debug signals',6,'MCU package signals; 2x10 factory footprint mapping not yet known','3.3 V logic','SWD/JTAG',[
              'SWDIO'=>['pin'=>44,'gpio'=>'GPIO38'],'SWCLK'=>['pin'=>45,'gpio'=>'GPIO39'],'TRSTn'=>['pin'=>46],'XRSTn'=>['pin'=>47],'BOOT'=>['pin'=>48,'gpio'=>'GPIO40'],'GND'=>['pin'=>'DVSS multiple']
          ],'documented','Official SPC1168 LQFP48 datasheet; not yet mapped to PCB pads'],
        ];
        $cs=$db->prepare('INSERT INTO board_connectors(printer_code,board_role,board_revision,connector_name,pin_count,function,voltage,bus,pinout_json,evidence_state,source,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(printer_code,board_role,board_revision,connector_name) DO UPDATE SET pin_count=excluded.pin_count,function=excluded.function,voltage=excluded.voltage,bus=excluded.bus,pinout_json=excluded.pinout_json,evidence_state=excluded.evidence_state,source=excluded.source,updated_at=excluded.updated_at');
        foreach($connectors as $r)$cs->execute([$r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$r[7],json_encode($r[8],JSON_UNESCAPED_SLASHES),$r[9],$r[10],$now]);
    }}
