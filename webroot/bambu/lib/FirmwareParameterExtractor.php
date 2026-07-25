<?php
declare(strict_types=1);

/**
 * Converts an archived Bambu OTA into an editable HotFetched-style project.
 * Values are intentionally provenance-aware: documented model specifications
 * are separate from values observed in the OTA filename/component inventory.
 */
final class FirmwareParameterExtractor {
    /** Internal OTA platform aliases observed in Bambu package names. */
    private const PLATFORM_ALIASES = [
        'n1' => 'a1-mini',
        'a1mini' => 'a1-mini',
        'a1-mini' => 'a1-mini',
        'a1' => 'a1',
        'p1p' => 'p1p',
        'p1s' => 'p1s',
        'x1c' => 'x1c',
        'h2d' => 'h2d',
    ];
    private const MODEL_PRESETS = [
        'a1-mini' => [
            'printer_code' => 'A1 mini',
            'machine_name' => 'Bambu Lab A1 mini',
            'kinematics' => 'Cartesian',
            'connectivity' => 'WiFi',
            'x_travel' => 180, 'y_travel' => 180, 'z_travel' => 180,
            'max_speed_xy' => 500, 'max_acceleration' => 10000,
            'hotend_max_temp' => 300, 'bed_max_temp' => 80,
            'chamber_max_temp' => null, 'bed_probe' => 'Full-auto calibration / bed leveling',
            'nozzle_diameter' => 0.4, 'extruder_count' => 1,
            'microsteps' => null,
            'source' => 'Bambu Lab A1 mini technical specifications + imported OTA evidence',
            'confidence' => 'documented',
        ],
        'a1' => [
            'printer_code' => 'A1', 'machine_name' => 'Bambu Lab A1',
            'kinematics' => 'Cartesian', 'connectivity' => 'WiFi',
            'x_travel' => 256, 'y_travel' => 256, 'z_travel' => 256,
            'max_speed_xy' => 500, 'max_acceleration' => 10000,
            'hotend_max_temp' => 300, 'bed_max_temp' => 100,
            'chamber_max_temp' => null, 'bed_probe' => 'Full-auto calibration / bed leveling',
            'nozzle_diameter' => 0.4, 'extruder_count' => 1, 'microsteps' => null,
            'source' => 'Bambu Lab A1 technical specifications + imported OTA evidence',
            'confidence' => 'documented',
        ],
        'p1p' => self::P1_BASE + ['printer_code'=>'P1P','machine_name'=>'Bambu Lab P1P','chamber_max_temp'=>null],
        'p1s' => self::P1_BASE + ['printer_code'=>'P1S','machine_name'=>'Bambu Lab P1S'],
        'x1c' => self::P1_BASE + ['printer_code'=>'X1C','machine_name'=>'Bambu Lab X1 Carbon','hotend_max_temp'=>300,'bed_probe'=>'Micro lidar + force sensors'],
        'h2d' => [
            'printer_code'=>'H2D','machine_name'=>'Bambu Lab H2D','kinematics'=>'CoreXY','connectivity'=>'WiFi / Ethernet',
            'x_travel'=>350,'y_travel'=>320,'z_travel'=>325,'max_speed_xy'=>1000,'max_acceleration'=>20000,
            'hotend_max_temp'=>350,'bed_max_temp'=>120,'chamber_max_temp'=>65,'bed_probe'=>'Vision + force sensing',
            'nozzle_diameter'=>0.4,'extruder_count'=>2,'microsteps'=>null,
            'source'=>'Selected Bambu model preset + imported OTA evidence','confidence'=>'documented',
        ],
    ];

    private const P1_BASE = [
        'kinematics'=>'CoreXY','connectivity'=>'WiFi / LAN',
        'x_travel'=>256,'y_travel'=>256,'z_travel'=>256,
        'max_speed_xy'=>500,'max_acceleration'=>20000,
        'hotend_max_temp'=>300,'bed_max_temp'=>100,'chamber_max_temp'=>60,
        'bed_probe'=>'Automatic bed leveling','nozzle_diameter'=>0.4,'extruder_count'=>1,'microsteps'=>null,
        'source'=>'Selected Bambu model preset + imported OTA evidence','confidence'=>'documented',
    ];

    public static function normalizeModel(string $requested, ?string $packageCode, string $filename): string {
        $requested = strtolower(trim($requested));
        if ($requested !== '' && $requested !== 'auto') {
            return self::PLATFORM_ALIASES[$requested] ?? $requested;
        }

        $code = strtolower(trim((string)$packageCode));
        if (isset(self::PLATFORM_ALIASES[$code])) return self::PLATFORM_ALIASES[$code];

        $hay = strtolower($filename.' '.$code);
        // Match the most specific labels first so "a1-mini" is not reduced to "a1".
        foreach (['a1-mini','a1mini','p1p','p1s','x1c','h2d','a1'] as $token) {
            if (str_contains($hay, $token)) return self::PLATFORM_ALIASES[$token] ?? $token;
        }
        return '';
    }

    /**
     * Re-run extraction for an existing project. Existing user-entered values are
     * retained unless $overwrite is true; blank fields are filled from the detected
     * model preset and linked OTA evidence.
     */
    public static function refillProfile(PDO $db, int $profileId, string $requestedModel='auto', bool $overwrite=false): int {
        $existing=PrinterProfiles::get($db,$profileId);
        if(!$existing) throw new RuntimeException('Project not found');
        $packageId=(int)($existing['firmware_package_id']??0);
        if($packageId<1) throw new RuntimeException('This project is not linked to an imported firmware ZIP');
        $fresh=self::projectData($db,$packageId,$requestedModel,(string)$existing['profile_name']);
        $merged=$existing;
        foreach(PrinterProfiles::DEFAULTS as $field=>$default){
            $current=$existing[$field]??null;
            $incoming=$fresh[$field]??null;
            $blank=$current===null || $current==='';
            // Defaults such as CoreXY/WiFi/16/1 are placeholders until a model is detected.
            $placeholder=in_array($field,['kinematics','connectivity','microsteps','extruder_count'],true)
                && $current===$default;
            if($overwrite || $blank || $placeholder){
                if($incoming!==null && $incoming!=='') $merged[$field]=$incoming;
            }
        }
        $merged['profile_id']=$profileId;
        $merged['printer_code']=$fresh['printer_code'] ?: $existing['printer_code'];
        $merged['profile_name']=$existing['profile_name'];
        $merged['firmware_package_id']=$packageId;
        // Preserve prior notes and append a concise re-extraction marker once.
        $marker="Auto-fill re-run from OTA #{$packageId}";
        $notes=trim((string)($existing['notes']??''));
        if(!str_contains($notes,$marker)) $merged['notes']=trim($notes."

".$marker);
        return PrinterProfiles::save($db,$merged);
    }

    public static function projectData(PDO $db, int $packageId, string $requestedModel, string $projectName=''): array {
        $s=$db->prepare('SELECT * FROM firmware_packages WHERE id=?');$s->execute([$packageId]);
        $package=$s->fetch(PDO::FETCH_ASSOC);
        if(!$package) throw new RuntimeException('Imported package was not found');
        $model=self::normalizeModel($requestedModel,$package['printer_code']??null,$package['original_name']);
        $preset=$model!=='' && isset(self::MODEL_PRESETS[$model]) ? self::MODEL_PRESETS[$model] : [];
        $code=(string)($preset['printer_code']??($package['printer_code']?:'unknown'));
        $version=(string)($package['version']?:'unknown');
        $build=(string)($package['build_stamp']?:'unknown');
        $components=$db->prepare('SELECT role,board_revision,version,filename FROM firmware_components WHERE package_id=? ORDER BY role,filename');
        $components->execute([$packageId]);
        $rows=$components->fetchAll(PDO::FETCH_ASSOC);
        $summary=[];
        foreach($rows as $r){
            $label=trim(($r['role']?:'PACKAGE').' '.($r['board_revision']?:''));
            $summary[]=$label.($r['version']?' v'.$r['version']:'').' — '.$r['filename'];
        }
        $name=trim($projectName);
        if($name==='') $name=$code.' firmware '.$version;
        $notes="Imported OTA: {$package['original_name']}\nSHA-256: {$package['sha256']}\nBuild: {$build}\n\nObserved components:\n- ".implode("\n- ",$summary);
        return array_merge(PrinterProfiles::DEFAULTS,$preset,[
            'printer_code'=>$code,
            'profile_name'=>$name,
            'source'=>trim((string)($preset['source']??'Imported Bambu OTA component inventory')),
            'confidence'=>(string)($preset['confidence']??'observed'),
            'notes'=>$notes,
            'firmware_package_id'=>$packageId,
        ]);
    }
}
