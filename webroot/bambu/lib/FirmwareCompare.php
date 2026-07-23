<?php
declare(strict_types=1);
final class FirmwareCompare {
    public static function run(PDO $db,int $a,int $b): array {
        $load=function(int $id)use($db){$s=$db->prepare('SELECT * FROM firmware_components WHERE package_id=? ORDER BY filename');$s->execute([$id]);$out=[];foreach($s as $r)$out[$r['role'].'|'.$r['board_revision'].'|'.$r['kind']]=$r;return $out;};
        $aa=$load($a);$bb=$load($b);$keys=array_unique(array_merge(array_keys($aa),array_keys($bb)));sort($keys);$rows=[];
        foreach($keys as $k){$x=$aa[$k]??null;$y=$bb[$k]??null;$status=!$x?'added':(!$y?'removed':($x['sha256']===$y['sha256']?'same':'changed'));$rows[]=['key'=>$k,'status'=>$status,'a'=>$x,'b'=>$y];}
        return $rows;
    }
}
