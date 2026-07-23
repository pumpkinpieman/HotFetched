<?php
declare(strict_types=1);
require_once __DIR__.'/BimhParser.php';
final class FirmwareImporter {
    public function __construct(private PDO $db, private string $privateDir) {}
    public function import(string $tmp, string $originalName, string $notes=''): int {
        if (!is_file($tmp)) throw new RuntimeException('Upload missing');
        $zip=new ZipArchive(); if($zip->open($tmp)!==true) throw new RuntimeException('Not a valid ZIP');
        if($zip->numFiles<1 || $zip->numFiles>500) throw new RuntimeException('Unexpected ZIP entry count');
        $sha=hash_file('sha256',$tmp); $bytes=filesize($tmp);
        $meta=$this->parsePackageName($originalName);
        $dir=rtrim($this->privateDir,'/').'/bambu/firmware'; if(!is_dir($dir))mkdir($dir,0775,true);
        $stored=$sha.'.zip'; $dest=$dir.'/'.$stored;
        if(!is_file($dest) && !copy($tmp,$dest)) throw new RuntimeException('Could not archive firmware');
        $this->db->beginTransaction();
        try {
            $s=$this->db->prepare('INSERT OR IGNORE INTO firmware_packages(sha256,original_name,stored_name,printer_code,version,build_stamp,bytes,imported_at,notes) VALUES(?,?,?,?,?,?,?,?,?)');
            $s->execute([$sha,basename($originalName),$stored,$meta['printer'],$meta['version'],$meta['build'],$bytes,gmdate('c'),$notes]);
            $q=$this->db->prepare('SELECT id FROM firmware_packages WHERE sha256=?');$q->execute([$sha]);$id=(int)$q->fetchColumn();
            $ins=$this->db->prepare('INSERT OR REPLACE INTO firmware_components(package_id,filename,kind,role,board_revision,version,build_stamp,bytes,sha256,container_magic,container_version,declared_size,embedded_names_json,manifest_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            for($i=0;$i<$zip->numFiles;$i++){
                $st=$zip->statIndex($i); $name=$st['name']; if(str_ends_with($name,'/'))continue;
                $data=$zip->getFromIndex($i); if($data===false)continue;
                $p=$this->parseComponentName($name); $bi=BimhParser::inspect($data);
                $manifest=$bi['json']['data']??null;
                $ins->execute([$id,$name,$p['kind'],$p['role'],$p['rev'],$p['version'],$p['build'],strlen($data),hash('sha256',$data),$bi['magic'],$bi['version'],$bi['declared_size'],json_encode($bi['embedded_strings'],JSON_UNESCAPED_SLASHES),$manifest?json_encode($manifest,JSON_UNESCAPED_SLASHES):null]);
                $this->upsertObservedBoard($meta['printer'] ?: 'unknown',$p,$id,$name,$manifest);
            }
            $this->db->commit(); return $id;
        } catch(Throwable $e){$this->db->rollBack();throw $e;} finally {$zip->close();}
    }
    private function parsePackageName(string $n): array {
        preg_match('/offline-ota-([a-z0-9-]+)_v([0-9.]+)-([0-9]{14})/i',$n,$m);
        return ['printer'=>$m[1]??null,'version'=>$m[2]??null,'build'=>$m[3]??null];
    }
    private function parseComponentName(string $n): array {
        $kind=preg_match('/\.([a-z0-9]+)\.sig$/i',$n,$m)?strtolower($m[1]):pathinfo($n,PATHINFO_EXTENSION);
        preg_match('/(?:^|[-_])(ap|mc|th|ams|n3f|n3s)(?:[-_]|$)/i',$n,$r);
        preg_match('/_rev([0-9]+)[-_]/i',$n,$rv);
        preg_match('/[-_]v([0-9.]+)[-_]/i',$n,$v);
        preg_match('/[-_]([0-9]{14})(?:_|\.|-)/',$n,$b);
        return ['kind'=>$kind,'role'=>strtoupper($r[1]??'PACKAGE'),'rev'=>isset($rv[1])?'rev'.$rv[1]:'','version'=>$v[1]??'','build'=>$b[1]??''];
    }
    private function upsertObservedBoard(string $printer,array $p,int $packageId,string $filename,?array $manifest): void {
        if($p['role']==='PACKAGE' && !$manifest)return;
        $params=['observed_component'=>$filename,'firmware_version'=>$p['version'],'first_package_id'=>$packageId];
        if($manifest)$params['manifest']=$manifest;
        $s=$this->db->prepare('INSERT INTO boards(printer_code,board_role,board_revision,mcu,parameters_json,confidence,source,updated_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(printer_code,board_role,board_revision) DO UPDATE SET parameters_json=excluded.parameters_json,updated_at=excluded.updated_at');
        $s->execute([$printer,$p['role'],$p['rev'],'',json_encode($params,JSON_UNESCAPED_SLASHES),'observed',$manifest?'Imported OTA manifest':'Imported OTA filename',gmdate('c')]);
    }
}
