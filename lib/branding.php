<?php
require_once __DIR__.'/../config/database.php';
function organizationBrand(): array {
    try {
        $row=db()->query('SELECT name,domain,logo_path FROM organization_settings WHERE id=1')->fetch();
        if($row){
            $row['name']=trim((string)($row['name']??''))?:'NonceBlox ATS';
            return $row;
        }
    } catch(Throwable $e) {}
    return ['name'=>'NonceBlox ATS','domain'=>null,'logo_path'=>null];
}
function brandedPageTitle(?string $title,array $brand): string {
    $name=trim((string)($brand['name']??''))?:'NonceBlox ATS';
    $title=trim((string)$title);
    if($title==='')return $name;
    $title=preg_replace('/\bResume\s*IQ\b/i',$name,$title);
    return str_contains($title,$name)?$title:$title.' · '.$name;
}