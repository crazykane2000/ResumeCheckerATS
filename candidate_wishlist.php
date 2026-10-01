<?php
require_once __DIR__.'/lib/auth.php';requireAuth();
if($_SERVER['REQUEST_METHOD']!=='POST'||!verifyCsrf($_POST['csrf']??'')){http_response_code(403);exit('Invalid request.');}
$user=currentUser();$profile=(int)($_POST['profile_id']??0);$candidate=trim($_POST['candidate_id']??'');
$check=db()->prepare('SELECT wp.name,c.role_title FROM wishlist_profiles wp JOIN candidates c ON c.id=? WHERE wp.id=? AND wp.user_id=?');
$check->execute([$candidate,$profile,$user['id']]);$match=$check->fetch();
$lock=db()->prepare('SELECT 1 FROM interview_invite_locks WHERE profile_id=? AND candidate_id=? AND active=1');$lock->execute([$profile,$candidate]);if($lock->fetchColumn()){http_response_code(409);exit('Candidate was already invited. Reset the interview invitation lock first.');}
$lock=db()->prepare('SELECT 1 FROM interview_invite_locks WHERE profile_id=? AND candidate_id=? AND active=1');$lock->execute([$profile,$candidate]);if($lock->fetchColumn()){http_response_code(409);exit('Candidate was already invited. Reset the interview invitation lock first.');}
$normalise=fn($value)=>preg_replace('/\s+/',' ',preg_replace('/^sr\.?\s+/','senior ',mb_strtolower(trim((string)$value))));
if(!$match||$normalise($match['name'])!==$normalise($match['role_title'])){http_response_code(422);exit('Candidate can only be added to the job they applied for.');}
db()->prepare("INSERT INTO wishlist_items(profile_id,candidate_id,disposition) VALUES(?,?,'wishlist') ON DUPLICATE KEY UPDATE disposition=IF(disposition='blacklisted',disposition,'wishlist')")->execute([$profile,$candidate]);
header('Location: wishlist.php?profile='.$profile);
