<?php
declare(strict_types=1);
final class BimhParser {
    public static function inspect(string $bytes): array {
        $len=strlen($bytes); $magic=substr($bytes,0,4);
        $u32=static fn(int $o): ?int => $o+4<=strlen($bytes) ? unpack('V',substr($bytes,$o,4))[1] : null;
        $strings=[];
        if (preg_match_all('/[\x20-\x7e]{8,240}/', $bytes, $m, PREG_OFFSET_CAPTURE)) {
            foreach($m[0] as [$s,$off]) if (preg_match('/\.(?:sig|bin|json|zip|pack)|firmware|ota|model_name|version/i',$s)) $strings[]=['offset'=>$off,'value'=>$s];
        }
        $json=self::extractJson($bytes);
        return [
          'magic'=>$magic,
          'is_bimh'=>$magic==='BIMH',
          'version'=>$u32(4),
          'declared_size'=>$u32(8),
          'header_words'=>array_map($u32,[12,16,20,24,28,32,36,40,44]),
          'embedded_strings'=>$strings,
          'json'=>$json,
        ];
    }

    /** Locate the first balanced JSON object that successfully decodes. */
    public static function extractJson(string $bytes): ?array {
        $length=strlen($bytes);
        for($start=0;$start<$length;$start++){
            if($bytes[$start]!=='{') continue;
            $depth=0;$quoted=false;$escape=false;
            for($i=$start;$i<$length;$i++){
                $c=$bytes[$i];
                if($quoted){
                    if($escape){$escape=false;continue;}
                    if($c==='\\'){$escape=true;continue;}
                    if($c==='"')$quoted=false;
                    continue;
                }
                if($c==='"'){$quoted=true;continue;}
                if($c==='{')$depth++;
                elseif($c==='}'){
                    $depth--;
                    if($depth===0){
                        $candidate=substr($bytes,$start,$i-$start+1);
                        $decoded=json_decode($candidate,true);
                        if(is_array($decoded)) return ['offset'=>$start,'length'=>strlen($candidate),'data'=>$decoded];
                        break;
                    }
                }
            }
        }
        return null;
    }
}
