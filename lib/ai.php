<?php
function aiEngineStatus(): array {
    $mode=getenv('RESUMEIQ_AI_MODE')?:'rules';$ready=$mode==='rules'||($mode==='structured_api'&&getenv('OPENAI_API_KEY'));
    try{require_once __DIR__.'/integrations.php';$fallback=integrationConfig('ai_fallback');foreach(($fallback['order']??[]) as $provider){$config=integrationConfig('ai_'.$provider);if(($config['status']??'')==='configured'&&!empty($config['api_key'])){$mode='structured_api';$ready=true;break;}}}catch(Throwable $e){}
    return ['mode'=>$mode,'ready'=>$ready,'label'=>$mode==='structured_api'?'Structured AI extraction':'Deterministic rules'];
}
function candidateOutputSchema(): array {
    return ['candidate'=>['name'=>'string|null','email'=>'string|null','phone'=>'string|null'],'roles'=>[['title'=>'string','start'=>'YYYY-MM','end'=>'YYYY-MM|present','skills'=>['string']]],'skill_tenure_months'=>['skill'=>'integer'],'employment_gaps'=>[['start'=>'YYYY-MM','end'=>'YYYY-MM','months'=>'integer']],'uncertainties'=>['string']];
}
