<?php
define('RESUMEIQ_FUNCTIONS_ONLY',true);$_SERVER['REQUEST_METHOD']='GET';
require_once __DIR__.'/../index.php';require_once __DIR__.'/../lib/workspace.php';
$pdo=db();$jobs=[];foreach($pdo->query('SELECT title,description FROM jobs') as $job)$jobs[mb_strtolower(trim($job['title']))]=$job['description'];
$select=$pdo->query('SELECT id,name,required_skills_json FROM wishlist_profiles');$update=$pdo->prepare('UPDATE wishlist_profiles SET required_skills_json=? WHERE id=?');$updated=0;$force=in_array('--force',$argv??[],true);
foreach($select as $profile){if(!$force&&json_decode($profile['required_skills_json']??'[]',true))continue;$description=$jobs[mb_strtolower(trim($profile['name']))]??'';if($description==='')continue;$skills=extractDetectedSkills(html_entity_decode(strip_tags($description),ENT_QUOTES|ENT_HTML5,'UTF-8'));if(!$skills)continue;$update->execute([json_encode(array_values($skills),JSON_UNESCAPED_SLASHES),$profile['id']]);$updated++;}
echo "Seeded canonical required skills for $updated profiles.\n";
