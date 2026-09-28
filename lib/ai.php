<?php
function aiEngineStatus(): array {
    $mode=getenv('RESUMEIQ_AI_MODE')?:'rules';
    return ['mode'=>$mode,'ready'=>$mode==='rules'||($mode==='structured_api'&&getenv('OPENAI_API_KEY')),'label'=>$mode==='structured_api'?'Structured AI extraction':'Deterministic rules'];
}
function candidateOutputSchema(): array {
    return ['candidate'=>['name'=>'string|null','email'=>'string|null','phone'=>'string|null'],'roles'=>[['title'=>'string','start'=>'YYYY-MM','end'=>'YYYY-MM|present','skills'=>['string']]],'skill_tenure_months'=>['skill'=>'integer'],'employment_gaps'=>[['start'=>'YYYY-MM','end'=>'YYYY-MM','months'=>'integer']],'uncertainties'=>['string']];
}
