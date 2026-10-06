<?php

/**
 * Deterministic candidate-to-job evidence mapping built from stored structured data.
 * This is advisory and never makes a hiring or pipeline decision.
 */
function applicationMatchSkillKey(string $skill): string {
    $key=normaliseSkill($skill);
    $aliases=['js'=>'javascript','node'=>'node.js','nodejs'=>'node.js','vue'=>'vue.js','vuejs'=>'vue.js','restful api'=>'rest api','restful apis'=>'rest api','smart contract'=>'smart contracts'];
    return $aliases[$key]??$key;
}

function applicationMatchRelations(): array {
    return [
        'php'=>['laravel','symfony','codeigniter'],
        'javascript'=>['react','angular','vue.js','node.js'],
        'python'=>['django','flask'],
        'blockchain'=>['web3','ethereum','solidity','smart contracts'],
        'rest api'=>['postman'],
    ];
}

function applicationRoleSimilarity(string $jobTitle,string $candidateRole): int {
    $tokens=static function(string $value):array{
        $ignored=['senior','junior','lead','manager','developer','engineer'];
        return array_values(array_unique(array_filter(preg_split('/[^\pL\pN+#.]+/u',mb_strtolower($value))?:[],static fn($token)=>mb_strlen($token)>2&&!in_array($token,$ignored,true))));
    };
    $jobTokens=$tokens($jobTitle);$roleTokens=$tokens($candidateRole);
    return $jobTokens?(int)round(count(array_intersect($jobTokens,$roleTokens))/count($jobTokens)*100):0;
}

function applicationEvidenceMatch(array $candidate,array $job): array {
    $required=array_values(array_unique(array_filter(array_map('trim',json_decode($job['required_skills_json']??'[]',true)?:[]))));
    $preferred=array_values(array_unique(array_filter(array_map('trim',json_decode($job['preferred_skills_json']??'[]',true)?:[]))));
    $candidateSkills=[];foreach($candidate['skills']??[] as $skill){$key=applicationMatchSkillKey((string)$skill);if($key!=='')$candidateSkills[$key]=trim((string)$skill);}
    $experienceMonths=[];foreach($candidate['experience']['skill_months']??[] as $skill=>$months)$experienceMonths[applicationMatchSkillKey((string)$skill)]=max(0,(int)$months);
    $analysis=$candidate['analysis']??[];
    $analyzed=(bool)$candidateSkills||!empty($experienceMonths)||!empty($candidate['experience']['jobs'])||!empty($analysis['text_length'])||!empty($analysis['raw_text'])||!empty($analysis['processing_status'])||isset($candidate['legacy_score']);
    $relations=applicationMatchRelations();$mapping=[];$mandatoryFactors=[];$evidenceFactors=[];$experienceRatios=[];
    $minimumMonths=max(0,(int)round((float)($job['min_experience']??0)*12));
    foreach($required as $requirement){
        $key=applicationMatchSkillKey($requirement);$months=$experienceMonths[$key]??0;$related=[];
        foreach($relations[$key]??[] as $relatedKey)if(isset($candidateSkills[$relatedKey]))$related[]=$candidateSkills[$relatedKey];
        if(!$analyzed){$status='needs_analysis';$factor=0;$evidence=0;$confidence=0;}
        elseif(isset($candidateSkills[$key])&&$months>0){$status='experience_backed';$factor=1.0;$evidence=.95;$confidence=.95;}
        elseif(isset($candidateSkills[$key])){$status='skills_only';$factor=.65;$evidence=.45;$confidence=.72;}
        elseif($related){$status='related_review';$factor=.30;$evidence=.20;$confidence=.45;}
        else{$status='not_found';$factor=0;$evidence=0;$confidence=0;}
        $experienceRatio=$minimumMonths>0?min(1,$months/$minimumMonths):($months>0?1:0);
        $mandatoryFactors[]=$factor;$evidenceFactors[]=$evidence;$experienceRatios[]=$experienceRatio;
        $mapping[]=['requirement'=>$requirement,'status'=>$status,'evidence'=>isset($candidateSkills[$key])?[$candidateSkills[$key]]:$related,'months'=>$months,'confidence'=>$confidence];
    }
    $configured=(bool)$required;
    $mandatory=$configured?array_sum($mandatoryFactors)/count($required)*40:0;
    $experience=$configured?array_sum($experienceRatios)/count($required)*25:0;
    $evidence=$configured?array_sum($evidenceFactors)/count($required)*15:0;
    $preferredMatches=[];foreach($preferred as $skill)if(isset($candidateSkills[applicationMatchSkillKey($skill)]))$preferredMatches[]=$skill;
    $preferredScore=$configured&&$analyzed&&$preferred?count($preferredMatches)/count($preferred)*10:0;
    $roleSimilarity=$configured&&$analyzed?applicationRoleSimilarity((string)($job['title']??''),(string)($candidate['role']??'')):0;
    $roleScore=$roleSimilarity/100*10;
    $total=$configured&&$analyzed?(int)round($mandatory+$experience+$preferredScore+$roleScore+$evidence):0;
    return ['configured'=>$configured,'analyzed'=>$analyzed,'score'=>$configured&&$analyzed?min(100,$total):0,'breakdown'=>['required_skills'=>round($mandatory,1),'relevant_experience'=>round($experience,1),'preferred_skills'=>round($preferredScore,1),'role_similarity'=>round($roleScore,1),'evidence_strength'=>round($evidence,1)],'maximum'=>['required_skills'=>40,'relevant_experience'=>25,'preferred_skills'=>10,'role_similarity'=>10,'evidence_strength'=>15],'mapping'=>$mapping,'preferred_matched'=>$preferredMatches,'role_similarity'=>$roleSimilarity,'version'=>'evidence_v1'];
}
