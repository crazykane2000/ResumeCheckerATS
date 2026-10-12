<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}

require_once __DIR__.'/../config/database.php';

$pdo=db();
$apply=in_array('--apply',$argv??[],true);
$actions=['candidate.resume_analyzed','candidate.resume_analysis_failed'];
$placeholders=implode(',',array_fill(0,count($actions),'?'));

$summary=$pdo->prepare("SELECT action,COUNT(*) event_count,ROUND(SUM(OCTET_LENGTH(metadata_json))/1024/1024,2) metadata_mb FROM audit_events WHERE action IN ($placeholders) GROUP BY action ORDER BY action");
$summary->execute($actions);
foreach($summary->fetchAll() as $row){
    echo $row['action'].' events='.$row['event_count'].' metadata_mb='.$row['metadata_mb'].PHP_EOL;
}

$duplicates=$pdo->prepare("SELECT COUNT(*) FROM audit_events ae LEFT JOIN (SELECT MAX(id) keep_id FROM audit_events WHERE action IN ($placeholders) GROUP BY action,entity_type,entity_id) kept ON kept.keep_id=ae.id WHERE ae.action IN ($placeholders) AND kept.keep_id IS NULL");
$duplicates->execute(array_merge($actions,$actions));
$duplicateCount=(int)$duplicates->fetchColumn();
echo 'duplicate_events='.$duplicateCount.PHP_EOL;

if(!$apply){
    echo "status=dry_run\nRe-run with --apply only after taking and verifying a database backup.\n";
    exit(0);
}

$pdo->exec('DROP TEMPORARY TABLE IF EXISTS audit_resume_analysis_keep');
$pdo->exec('CREATE TEMPORARY TABLE audit_resume_analysis_keep (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$keep=$pdo->prepare("INSERT INTO audit_resume_analysis_keep(id) SELECT MAX(id) FROM audit_events WHERE action IN ($placeholders) GROUP BY action,entity_type,entity_id");
$keep->execute($actions);

$deleted=0;
do{
    $find=$pdo->prepare("SELECT ae.id FROM audit_events ae LEFT JOIN audit_resume_analysis_keep kept ON kept.id=ae.id WHERE ae.action IN ($placeholders) AND kept.id IS NULL ORDER BY ae.id LIMIT 5000");
    $find->execute($actions);
    $ids=array_map('intval',$find->fetchAll(PDO::FETCH_COLUMN));
    $batch=count($ids);
    if($batch>0){
        $deletePlaceholders=implode(',',array_fill(0,$batch,'?'));
        $delete=$pdo->prepare("DELETE FROM audit_events WHERE id IN ($deletePlaceholders)");
        $delete->execute($ids);
    }
    $deleted+=$batch;
    echo 'deleted='.$deleted.PHP_EOL;
}while($batch===5000);

echo "status=complete\nretained=latest event per candidate and analysis outcome\n";
