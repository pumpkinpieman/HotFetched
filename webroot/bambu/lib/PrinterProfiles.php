<?php
declare(strict_types=1);
final class PrinterProfiles {
    public const AXES=['x','y','z','z2','e0','e1','e2','e3'];
    public const DEFAULTS = [
        'machine_name' => '', 'kinematics' => 'Unknown', 'connectivity' => '',
        'x_travel' => null, 'y_travel' => null, 'z_travel' => null,
        'max_speed_xy' => null, 'max_acceleration' => null,
        'steps_x' => null, 'steps_y' => null, 'steps_z' => null, 'steps_z2' => null,
        'steps_e0' => null, 'steps_e1' => null, 'steps_e2' => null, 'steps_e3' => null,
        'invert_x' => 0, 'invert_y' => 0, 'invert_z' => 0, 'invert_z2' => 0,
        'invert_e0' => 0, 'invert_e1' => 0, 'invert_e2' => 0, 'invert_e3' => 0,
        'direction_x'=>'unknown','direction_y'=>'unknown','direction_z'=>'unknown','direction_z2'=>'unknown',
        'direction_e0'=>'unknown','direction_e1'=>'unknown','direction_e2'=>'unknown','direction_e3'=>'unknown',
        'current_x' => null, 'current_y' => null, 'current_z' => null, 'current_z2' => null,
        'current_e0' => null, 'current_e1' => null, 'current_e2' => null, 'current_e3' => null,
        'microsteps' => null, 'hotend_max_temp' => null, 'bed_max_temp' => null,
        'chamber_max_temp' => null, 'bed_probe' => '', 'nozzle_diameter' => null,
        'extruder_count' => null, 'notes' => '', 'source' => 'manual', 'confidence' => 'unknown',
        'firmware_package_id' => null,
    ];
    public static function get(PDO $db, int $id): ?array {
        $s=$db->prepare('SELECT * FROM printer_profiles WHERE id=?');$s->execute([$id]);
        $r=$s->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
    public static function list(PDO $db): array {return $db->query('SELECT * FROM printer_profiles ORDER BY printer_code, profile_name')->fetchAll(PDO::FETCH_ASSOC);}
    public static function save(PDO $db, array $input): int {
        $id=(int)($input['profile_id']??0);$printer=trim((string)($input['printer_code']??''));
        if($printer==='') throw new InvalidArgumentException('Printer/platform is required');
        $name=trim((string)($input['profile_name']??''));if($name==='')$name=$printer.' research profile';
        $fields=array_keys(self::DEFAULTS);$row=[];
        foreach($fields as $f){
            $v=$input[$f]??self::DEFAULTS[$f];
            if(str_starts_with($f,'direction_')){$v=in_array($v,['unknown','normal','inverted'],true)?$v:'unknown';}
            elseif(str_starts_with($f,'invert_')){$axis=substr($f,7);$v=(($input['direction_'.$axis]??'unknown')==='inverted')?1:0;}
            elseif(in_array($f,['machine_name','kinematics','connectivity','bed_probe','notes','source','confidence'],true))$v=trim((string)$v);
            elseif($v===''||$v===null)$v=null;elseif(is_numeric($v))$v=0+$v;$row[$f]=$v;
        }
        $now=gmdate('c');
        if($id>0){$sets=[];$vals=[];foreach($fields as $f){$sets[]="$f=?";$vals[]=$row[$f];}$vals[]=$printer;$vals[]=$name;$vals[]=$now;$vals[]=$id;$db->prepare('UPDATE printer_profiles SET '.implode(',',$sets).',printer_code=?,profile_name=?,updated_at=? WHERE id=?')->execute($vals);self::syncEvidence($db,$id,$input);return $id;}
        $cols=array_merge(['printer_code','profile_name'],$fields,['created_at','updated_at']);$vals=array_merge([$printer,$name],array_values($row),[$now,$now]);
        $db->prepare('INSERT INTO printer_profiles('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')')->execute($vals);$id=(int)$db->lastInsertId();self::syncEvidence($db,$id,$input);return $id;
    }
    private static function syncEvidence(PDO $db,int $id,array $input):void{
        $now=gmdate('c');$s=$db->prepare('INSERT INTO parameter_evidence(profile_id,field_name,evidence_state,source,detail,updated_at) VALUES(?,?,?,?,?,?) ON CONFLICT(profile_id,field_name) DO UPDATE SET evidence_state=excluded.evidence_state,source=excluded.source,detail=excluded.detail,updated_at=excluded.updated_at');
        foreach(self::criticalFields() as $field){$state=(string)($input['evidence_'.$field]??'unknown');if(!in_array($state,['unknown','inferred','observed','documented','measured','extracted','verified'],true))$state='unknown';$s->execute([$id,$field,$state,trim((string)($input['evidence_source_'.$field]??'')),trim((string)($input['evidence_detail_'.$field]??'')),$now]);}
    }
    public static function evidence(PDO $db,int $id):array{$s=$db->prepare('SELECT * FROM parameter_evidence WHERE profile_id=?');$s->execute([$id]);$out=[];foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['field_name']]=$r;return $out;}
    public static function criticalFields():array{return ['steps_x','steps_y','steps_z','steps_e0','direction_x','direction_y','direction_z','direction_e0','current_x','current_y','current_z','current_e0','microsteps'];}
    public static function readiness(PDO $db,array $profile):array{$ev=!empty($profile['id'])?self::evidence($db,(int)$profile['id']):[];$missing=[];foreach(self::criticalFields() as $f){$value=$profile[$f]??null;$knownValue=!($value===null||$value===''||$value==='unknown');$state=$ev[$f]['evidence_state']??'unknown';if(!$knownValue||!in_array($state,['measured','extracted','verified'],true))$missing[]=['field'=>$f,'value'=>$value,'state'=>$state];}return ['ready'=>count($missing)===0,'missing'=>$missing];}
}
