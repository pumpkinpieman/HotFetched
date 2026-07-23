<?php
declare(strict_types=1);
final class ProjectExporter {
    public static function export(PDO $db,int $profileId,string $privateDir): string {
        $s=$db->prepare('SELECT * FROM printer_profiles WHERE id=?');$s->execute([$profileId]);$profile=$s->fetch(PDO::FETCH_ASSOC);
        if(!$profile)throw new RuntimeException('Project not found');
        $package=null;$components=[];
        if(!empty($profile['firmware_package_id'])){
            $s=$db->prepare('SELECT * FROM firmware_packages WHERE id=?');$s->execute([$profile['firmware_package_id']]);$package=$s->fetch(PDO::FETCH_ASSOC)?:null;
            $s=$db->prepare('SELECT filename,kind,role,board_revision,version,build_stamp,bytes,sha256,container_magic,container_version,declared_size,manifest_json FROM firmware_components WHERE package_id=? ORDER BY filename');$s->execute([$profile['firmware_package_id']]);$components=$s->fetchAll(PDO::FETCH_ASSOC);
        }
        $payload=['format'=>'hotfetched-bambu-research-project','format_version'=>1,'exported_at'=>gmdate('c'),'project'=>$profile,'official_firmware'=>$package,'components'=>$components,'limitations'=>['This export is a research/profile bundle, not a vendor-signed installable firmware image.','Original signed OTA remains unchanged and is referenced by SHA-256.']];
        $dir=rtrim($privateDir,'/').'/bambu/reports';if(!is_dir($dir))mkdir($dir,0775,true);
        $safe=preg_replace('/[^A-Za-z0-9._-]+/','-',trim($profile['printer_code'].'-'.$profile['profile_name'],'-'))?:'bambu-project';
        $path=$dir.'/'.$safe.'-'.gmdate('YmdHis').'.json';
        file_put_contents($path,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        return $path;
    }
}
