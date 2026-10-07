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

function publicBrandLogoUrl(array $brand, string $baseUrl = ''): string {
    $logoPath = trim((string)($brand['logo_path'] ?? ''));
    if (str_starts_with($logoPath, 'http://') || str_starts_with($logoPath, 'https://')) {
        return $logoPath;
    }
    $domain = trim((string)($brand['domain'] ?? ''));
    if (!empty($domain) && !empty($logoPath)) {
        return 'https://' . rtrim($domain, '/') . '/' . ltrim($logoPath, '/');
    }
    if (!empty($baseUrl) && !empty($logoPath)) {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if ($host && !in_array($host, ['localhost', '127.0.0.1', '::1'], true) && !str_starts_with($host, '192.168.') && !str_starts_with($host, '10.')) {
            return rtrim($baseUrl, '/') . '/' . ltrim($logoPath, '/');
        }
    }
    return 'https://nonceblox.com/logos.png';
}

function brandedPageTitle(?string $title,array $brand): string {
    $name=trim((string)($brand['name']??''))?:'NonceBlox ATS';
    $title=trim((string)$title);
    if($title==='')return $name;
    $title=preg_replace('/\bResume\s*IQ\b/i',$name,$title);
    return str_contains($title,$name)?$title:$title.' · '.$name;
}