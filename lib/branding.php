<?php
require_once __DIR__.'/../config/database.php';
function organizationBrand(): array {try{$row=db()->query('SELECT name,domain,logo_path FROM organization_settings WHERE id=1')->fetch();if($row)return $row;}catch(Throwable $e){}return ['name'=>'ResumeIQ','domain'=>null,'logo_path'=>null];}
