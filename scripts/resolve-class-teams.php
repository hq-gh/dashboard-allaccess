<?php declare(strict_types=1);
/**
 * Resuelve class_id -> Team para los subdominios team_based, SIN intervención manual.
 *  - KNOWN: class_id cuyo hotmart_club_classes.class_name ya trae "Team N".
 *  - INFERIDO: class_id sin nombre → se infiere el Team por la fecha de la cohorte
 *    (mediana de first_access de sus alumnos) contra teams.fecha_inicio, eligiendo
 *    el Team NO cubierto más cercano (±14 días). Hotmart no expone el nombre por API,
 *    así que esta es la única vía automática.
 *
 * Escribe en public.club_class_team (subdomain, class_id, team, team_number, source).
 * Idempotente (TRUNCATE + reinsert). Reporta precisión re-infiriendo las KNOWN.
 *
 * Uso: php scripts/resolve-class-teams.php [--apply]   (sin --apply = solo reporte)
 */
function load_env(string $p): void { if(!is_file($p))return; foreach(file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){ $l=trim($l); if($l===''||str_starts_with($l,'#'))continue; [$k,$v]=array_pad(explode('=',$l,2),2,''); $k=trim($k);$v=trim($v," \t\"'"); if($k!==''&&getenv($k)===false){putenv("$k=$v");$_ENV[$k]=$v;} } }
$root=dirname(__DIR__); load_env($root.'/.env'); require $root.'/vendor/autoload.php';
$APPLY=in_array('--apply',$argv,true);
$pdo=new PDO("pgsql:host=".getenv('DB_HOST').";dbname=".(getenv('DB_NAME')?:'neondb').";sslmode=require",getenv('DB_USER'),getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

$subs=array_column($pdo->query("SELECT subdomain FROM program_config WHERE access_type='team_based' AND is_active AND subdomain IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC),'subdomain');
// teams: team_number -> [team, fecha_inicio]
$teams=[]; foreach($pdo->query("SELECT team, team_number, fecha_inicio FROM teams WHERE fecha_inicio IS NOT NULL ORDER BY fecha_inicio") as $r) $teams[]=$r;

// infiere team para una cohort_date entre teams no cubiertos
$infer=function(string $cohort, array $covered) use($teams){
  // El acceso de una cohorte arranca 1-2 días ANTES de fecha_inicio del Team, así
  // que el Team correcto es el PRIMERO que inicia en/después de la mediana de acceso
  // (con 2 días de holgura hacia atrás). teams ya viene ordenado por fecha_inicio.
  $c=strtotime($cohort)-2*86400;
  foreach($teams as $t){ if(isset($covered[$t['team']])) continue;
    $fi=strtotime($t['fecha_inicio']);
    if($fi>=$c && ($fi-$c)/86400<=14) return $t;   // primer inicio >= (cohort-2d), dentro de 14d
  }
  // fallback: el más cercano (por si la cohorte quedó después del último inicio)
  $best=null;$bestd=null;
  foreach($teams as $t){ if(isset($covered[$t['team']])) continue;
    $d=abs((strtotime($t['fecha_inicio'])-strtotime($cohort))/86400);
    if($bestd===null||$d<$bestd){$bestd=$d;$best=$t;} }
  return ($best && $bestd<=10)?$best:null;
};

$rows=[]; $accOk=0;$accTot=0; $report=[];
foreach($subs as $sd){
  // class_id -> teamLabel conocido
  $known=[]; foreach($pdo->query("SELECT class_id, (regexp_match(class_name,'^Team ([0-9]+)'))[1] n FROM hotmart_club_classes WHERE subdomain=".$pdo->quote($sd)." AND class_name ~ '^Team [0-9]+'") as $r) if($r['n']!==null) $known[$r['class_id']]='Team '.$r['n'];
  $covered=array_flip(array_values($known));
  // cohort_date por class_id (mediana first_access)
  $st=$pdo->prepare("SELECT class_id, to_timestamp((percentile_cont(0.5) WITHIN GROUP (ORDER BY first_access_date))/1000.0)::date cohort, COUNT(*) n FROM club_students WHERE subdomain=:sd AND class_id IS NOT NULL AND first_access_date IS NOT NULL GROUP BY class_id");
  $st->execute([':sd'=>$sd]); $cohorts=[]; foreach($st as $r) $cohorts[$r['class_id']]=$r['cohort'];

  // PRECISIÓN: re-inferir las KNOWN (excluyéndose) y comparar
  foreach($known as $cid=>$lbl){ if(!isset($cohorts[$cid]))continue; $cov2=$covered; unset($cov2[$lbl]); $g=$infer($cohorts[$cid],$cov2); $accTot++; if($g && $g['team']===$lbl)$accOk++; }

  // #alumnos por class_id (todos, no solo con first_access)
  $cnt=[]; $stc=$pdo->prepare("SELECT class_id, COUNT(*) n FROM club_students WHERE subdomain=:sd AND class_id IS NOT NULL AND class_id<>'' GROUP BY class_id");
  $stc->execute([':sd'=>$sd]); foreach($stc as $r) $cnt[$r['class_id']]=(int)$r['n'];

  $rep=['sd'=>$sd,'known_cls'=>0,'known_stu'=>0,'inf_cls'=>0,'inf_stu'=>0,'unres_cls'=>0,'unres_stu'=>0,'inferred'=>[]];
  foreach($cnt as $cid=>$n){
    if(isset($known[$cid])){ $rep['known_cls']++; $rep['known_stu']+=$n;
      $t=null; foreach($teams as $x) if($x['team']===$known[$cid]){$t=$x;break;}
      if($t) $rows[]=[$sd,$cid,$t['team'],(int)$t['team_number'],'name'];
    } else {
      $g = isset($cohorts[$cid]) ? $infer($cohorts[$cid],$covered) : null;
      if($g){ $rep['inf_cls']++; $rep['inf_stu']+=$n; $rep['inferred'][]="$cid→{$g['team']} ($n al.)"; $rows[]=[$sd,$cid,$g['team'],(int)$g['team_number'],'inferred']; }
      else { $rep['unres_cls']++; $rep['unres_stu']+=$n; }
    }
  }
  $report[]=$rep;
}
echo "=== RESCATE por inferencia (por subdominio) ===\n";
$TI=0;$TS=0;$TU=0;$TUS=0;
foreach($report as $r){ printf("  %-22s conocidos=%-3s/%-5sal | RESCATA inf=%-3s/%-5sal | sin_rescatar=%-3s/%-4sal\n",$r['sd'],$r['known_cls'],$r['known_stu'],$r['inf_cls'],$r['inf_stu'],$r['unres_cls'],$r['unres_stu']); $TI+=$r['inf_cls'];$TS+=$r['inf_stu'];$TU+=$r['unres_cls'];$TUS+=$r['unres_stu'];
  foreach($r['inferred'] as $x) echo "       $x\n"; }
echo "TOTAL: grupos rescatados por inferencia=$TI ($TS alumnos) | grupos sin rescatar=$TU ($TUS alumnos)\n";
$byMethod=array_count_values(array_column($rows,4));
echo "Subdominios team_based: ".implode(',',$subs)."\n";
echo "Mappings: ".count($rows)." (name=".($byMethod['name']??0).", inferred=".($byMethod['inferred']??0).")\n";
echo "PRECISIÓN inferencia (re-infiriendo KNOWN): $accOk/$accTot = ".($accTot?round(100*$accOk/$accTot):0)."%\n";
// caso adela: class EM7qEynR7x (8eightfit1)
foreach($rows as $r) if($r[1]==='EM7qEynR7x') echo "adela class EM7qEynR7x -> {$r[2]} (source {$r[4]})\n";

if($APPLY){
  // Escribir SOLO los INFERIDOS en hotmart_club_classes como 'Team N (inferido)'.
  // El motor ya resuelve por ese nombre (regex '^Team [0-9]+'); cuando llegue el CSV
  // real, import-classes-from-csv.php hace UPSERT (subdomain,class_id) y lo corrige.
  $inf=array_values(array_filter($rows,fn($r)=>$r[4]==='inferred'));
  $up=$pdo->prepare("INSERT INTO public.hotmart_club_classes (subdomain,class_id,class_name,is_active)
     VALUES (?,?,?,TRUE)
     ON CONFLICT (subdomain,class_id) DO UPDATE SET class_name=EXCLUDED.class_name, is_active=TRUE, updated_at=NOW()");
  $pdo->beginTransaction();
  foreach($inf as $r) $up->execute([$r[0],$r[1],$r[2].' (inferido)']);
  $pdo->commit();
  echo "ESCRITO en hotmart_club_classes: ".count($inf)." mapeos inferidos (Team N (inferido)).\n";
} else echo "(dry-run: nada escrito. Corre con --apply)\n";
