<?php

function validateInterviewWindow(array $input): array
{
    $timezone=trim((string)($input['timezone']??'Asia/Kolkata'));
    try{$zone=new DateTimeZone($timezone);}catch(Throwable $e){throw new InvalidArgumentException('Select a valid timezone.');}
    $start=DateTimeImmutable::createFromFormat('!Y-m-d',(string)($input['availability_start']??''),$zone);
    $end=DateTimeImmutable::createFromFormat('!Y-m-d',(string)($input['availability_end']??''),$zone);
    if(!$start||!$end||$start->format('Y-m-d')!==($input['availability_start']??'')||$end->format('Y-m-d')!==($input['availability_end']??''))throw new InvalidArgumentException('Select a valid availability date range.');
    if($end<$start)throw new InvalidArgumentException('Availability end date must be on or after start date.');
    if((int)$start->diff($end)->format('%a')>30)throw new InvalidArgumentException('Availability window cannot exceed 30 days.');
    $dailyStart=(string)($input['daily_start']??'10:00');$dailyEnd=(string)($input['daily_end']??'17:00');
    if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$dailyStart)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$dailyEnd)||$dailyEnd<=$dailyStart)throw new InvalidArgumentException('Daily end time must be after the start time.');
    $slotMinutes=(int)($input['slot_minutes']??30);$bufferMinutes=(int)($input['buffer_minutes']??0);
    if(!in_array($slotMinutes,[15,30,45,60],true)||$bufferMinutes<0||$bufferMinutes>60)throw new InvalidArgumentException('Choose a supported slot duration and a buffer up to 60 minutes.');
    $workingDays=array_map('intval',(array)($input['working_days']??[1,2,3,4,5]));
    $workingDays=array_values(array_filter($workingDays,fn($d)=>$d>=1&&$d<=7));
    if(empty($workingDays))$workingDays=[1,2,3,4,5];
    $dayHours=[];
    if(!empty($input['day_hours'])&&is_array($input['day_hours'])){
        foreach($input['day_hours'] as $d=>$h){
            $dInt=(int)$d;
            if($dInt>=1&&$dInt<=7&&!empty($h['start'])&&!empty($h['end'])){
                if(preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$h['start'])&&preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$h['end'])&&$h['end']>$h['start']){
                    $dayHours[$dInt]=['start'=>$h['start'],'end'=>$h['end']];
                }
            }
        }
    }
    return compact('zone','timezone','start','end','dailyStart','dailyEnd','slotMinutes','bufferMinutes','workingDays','dayHours');
}

function buildInterviewSlots(array $window): array
{
    $slots=[];
    $workingDays=$window['workingDays']??[1,2,3,4,5];
    for($day=$window['start'];$day<=$window['end'];$day=$day->modify('+1 day')){
        $dayOfWeek=(int)$day->format('N');
        if(!in_array($dayOfWeek,$workingDays,true))continue;
        $dStart=$window['dayHours'][$dayOfWeek]['start']??$window['dailyStart'];
        $dEnd=$window['dayHours'][$dayOfWeek]['end']??$window['dailyEnd'];
        $cursor=new DateTimeImmutable($day->format('Y-m-d').' '.$dStart,$window['zone']);
        $dayEnd=new DateTimeImmutable($day->format('Y-m-d').' '.$dEnd,$window['zone']);
        while(($slotEnd=$cursor->modify('+'.$window['slotMinutes'].' minutes'))<=$dayEnd){
            $slots[]=['starts_at'=>$cursor->format('Y-m-d H:i:s'),'ends_at'=>$slotEnd->format('Y-m-d H:i:s')];
            $cursor=$slotEnd->modify('+'.$window['bufferMinutes'].' minutes');
        }
    }
    if(!$slots)throw new InvalidArgumentException('The selected window contains no active working-hour interview slots.');
    return $slots;
}

function newInterviewBookingToken(): array
{
    $raw=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');return ['raw'=>$raw,'hash'=>hash('sha256',$raw)];
}
