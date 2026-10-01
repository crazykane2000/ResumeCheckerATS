<?php

// Deploy as:
// https://nonceblox.com/interview-schedule.php

$apiUrl = getenv('RESUMEIQ_SCHEDULING_API_URL')
    ?: 'https://resume.nonceblox.com/public_interview_api.php';

$token = trim(
    (string)(
        $_GET['token']
        ?? $_POST['token']
        ?? ''
    )
);

$error = '';
$data = [];


/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

function scheduleApi(
    string $url,
    string $token,
    ?int $slotId = null
): array {

    if (
        !filter_var($url, FILTER_VALIDATE_URL)
        ||
        parse_url($url, PHP_URL_SCHEME) !== 'https'
    ) {
        throw new RuntimeException(
            'Scheduling service is not configured.'
        );
    }


    $curl = curl_init();


    $options = [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_TIMEOUT => 20,

        CURLOPT_CONNECTTIMEOUT => 8,

        CURLOPT_FOLLOWLOCATION => false,

        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]

    ];


    if ($slotId === null) {

        $options[CURLOPT_URL] =
            $url
            . '?token='
            . rawurlencode($token);

    } else {

        $options[CURLOPT_URL] =
            $url;

        $options[CURLOPT_POST] =
            true;

        $options[CURLOPT_HTTPHEADER] = [
            'Accept: application/json',
            'Content-Type: application/json'
        ];

        $options[CURLOPT_POSTFIELDS] =
            json_encode([
                'token' => $token,
                'slot_id' => $slotId
            ]);

    }


    curl_setopt_array(
        $curl,
        $options
    );


    $body =
        curl_exec($curl);


    $status =
        (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );


    $networkError =
        curl_error($curl);


    curl_close($curl);


    if (
        $body === false
        ||
        $networkError !== ''
        ||
        $status >= 500
    ) {

        throw new RuntimeException(
            'Scheduling service is temporarily unavailable.'
        );

    }


    $decoded =
        json_decode(
            (string)$body,
            true
        );


    if (!is_array($decoded)) {

        throw new RuntimeException(
            'Scheduling service returned an invalid response.'
        );

    }


    return $decoded;
}



/*
|--------------------------------------------------------------------------
| LOAD / CONFIRM
|--------------------------------------------------------------------------
*/

if (
    !preg_match(
        '/^[A-Za-z0-9_-]{40,60}$/',
        $token
    )
) {

    $error =
        'This interview link is invalid.';

} else {

    try {

        if (
            $_SERVER['REQUEST_METHOD']
            === 'POST'
        ) {

            $slotId =
                (int)(
                    $_POST['slot_id']
                    ?? 0
                );


            $data =
                scheduleApi(
                    $apiUrl,
                    $token,
                    $slotId
                );

        } else {

            $data =
                scheduleApi(
                    $apiUrl,
                    $token
                );

        }


        if (
            empty($data['ok'])
        ) {

            $error =
                $data['error']
                ?? 'This interview link is unavailable.';

        }

    } catch (Throwable $e) {

        $error =
            $e->getMessage();

    }

}



/*
|--------------------------------------------------------------------------
| GROUP SLOTS
|--------------------------------------------------------------------------
*/

$slotsByDay = [];


foreach (
    $data['slots'] ?? []
    as $slot
) {

    if (
        empty($slot['starts_at'])
    ) {
        continue;
    }


    $dateKey =
        substr(
            $slot['starts_at'],
            0,
            10
        );


    $slotsByDay[$dateKey][] =
        $slot;

}

?>
<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    Schedule your interview | NonceBlox
</title>


<link
    rel="preconnect"
    href="https://fonts.googleapis.com"
>

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
>

<link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap"
    rel="stylesheet"
>


<style>

:root{

    --bg:#f7f7f9;

    --white:#ffffff;

    --ink:#1f1e24;

    --muted:#77737f;

    --line:#e7e5eb;

    --line-dark:#d4d0dc;

    --primary:#6843e9;

    --primary-hover:#5733d0;

    --primary-soft:#f5f2ff;

    --danger:#a93f54;

    --danger-bg:#fff3f5;

    --success:#0d8055;

    --success-bg:#eff9f4;

}


*{
    box-sizing:border-box;
}


html,
body{
    margin:0;
    padding:0;
}


body{

    min-height:100vh;

    font-family:
        Inter,
        Arial,
        sans-serif;

    color:var(--ink);

    background:
        radial-gradient(
            circle at 90% 5%,
            rgba(104,67,233,.06),
            transparent 25%
        ),
        var(--bg);

    padding:28px 16px;

}


button,
input{
    font:inherit;
}


main{

    width:min(
        1120px,
        100%
    );

    margin:0 auto;

}



/*
|--------------------------------------------------------------------------
| OUTER CARD
|--------------------------------------------------------------------------
*/

.shell{

    background:#fff;

    border:1px solid var(--line);

    border-radius:10px;

    overflow:hidden;

    box-shadow:
        0 12px 35px
        rgba(30,20,60,.04);

}



/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

.topbar{

    height:74px;

    display:flex;

    align-items:center;

    padding:
        0 26px;

    background:#fff;

    border-bottom:
        1px solid
        var(--line);

}


.logo{

    display:block;

    width:auto;

    max-width:170px;

    max-height:38px;

}



/*
|--------------------------------------------------------------------------
| LAYOUT
|--------------------------------------------------------------------------
*/

.layout{

    display:grid;

    grid-template-columns:
        340px
        minmax(0,1fr);

    min-height:610px;

}



/*
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
*/

.sidebar{

    padding:
        32px 26px;

    background:#fbfbfc;

    border-right:
        1px solid
        var(--line);

}


.kicker{

    margin-bottom:12px;

    color:var(--primary);

    font-size:11px;

    font-weight:600;

    text-transform:uppercase;

    letter-spacing:.10em;

}


.sidebar h1{

    margin:
        0 0 14px;

    max-width:280px;

    font-size:30px;

    line-height:1.12;

    letter-spacing:-.035em;

    font-weight:600;

}


.sidebar > p{

    margin:0;

    color:var(--muted);

    font-size:14px;

    line-height:1.7;

}


.sidebar p strong{

    color:#37343c;

    font-weight:500;

}



/*
|--------------------------------------------------------------------------
| MINI CALENDAR
|--------------------------------------------------------------------------
*/

.calendar{

    margin-top:28px;

    padding:16px;

    background:#fff;

    border:
        1px solid
        var(--line);

    border-radius:8px;

}


.calendar-top{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:10px;

    margin-bottom:16px;

}


.calendar-title{

    font-size:13px;

    font-weight:600;

    color:#3d3944;

}


.timezone{

    padding:
        5px 8px;

    border-radius:5px;

    background:
        var(--primary-soft);

    color:
        var(--primary);

    font-size:10px;

    font-weight:500;

}


.calendar-grid{

    display:grid;

    grid-template-columns:
        repeat(
            7,
            1fr
        );

    gap:6px;

}


.calendar-grid span{

    display:flex;

    align-items:center;

    justify-content:center;

    aspect-ratio:1;

    border:
        1px solid
        #efedf2;

    border-radius:4px;

    background:#fafafa;

    color:#686370;

    font-size:11px;

    font-weight:500;

}


.calendar-grid span.head{

    aspect-ratio:auto;

    padding-bottom:2px;

    border:0;

    background:transparent;

    color:#aaa5af;

    font-size:10px;

}


.calendar-grid span.active{

    background:
        var(--primary);

    border-color:
        var(--primary);

    color:#fff;

}



/*
|--------------------------------------------------------------------------
| SIDE INFO
|--------------------------------------------------------------------------
*/

.side-info{

    margin-top:16px;

    display:grid;

    gap:8px;

}


.info{

    padding:
        13px 14px;

    background:#fff;

    border:
        1px solid
        var(--line);

    border-radius:7px;

}


.info strong{

    display:block;

    margin-bottom:4px;

    color:#37343c;

    font-size:12px;

    font-weight:600;

}


.info span{

    display:block;

    color:var(--muted);

    font-size:12px;

    line-height:1.55;

}



/*
|--------------------------------------------------------------------------
| CONTENT
|--------------------------------------------------------------------------
*/

.content{

    padding:30px;

    background:#fff;

}


.content-header{

    margin-bottom:20px;

}


.content-header h2{

    margin:0;

    color:#1e1d22;

    font-size:22px;

    font-weight:600;

    letter-spacing:-.025em;

}


.content-header p{

    margin:
        6px 0 0;

    color:var(--muted);

    font-size:13px;

    line-height:1.6;

}



/*
|--------------------------------------------------------------------------
| SELECTION BAR
|--------------------------------------------------------------------------
*/

.selection{

    margin-bottom:18px;

    padding:
        12px 14px;

    background:#fafafa;

    border:
        1px solid
        var(--line);

    border-radius:7px;

}


.selection-title{

    margin-bottom:4px;

    color:#827c89;

    font-size:10px;

    font-weight:600;

    text-transform:uppercase;

    letter-spacing:.08em;

}


.selection-text{

    color:#3f3b45;

    font-size:14px;

    font-weight:500;

}



/*
|--------------------------------------------------------------------------
| DAY GRID
|--------------------------------------------------------------------------
*/

.days{

    display:grid;

    grid-template-columns:
        repeat(
            2,
            minmax(0,1fr)
        );

    gap:12px;

}


.day-card{

    overflow:hidden;

    border:
        1px solid
        var(--line);

    border-radius:8px;

    background:#fff;

    transition:
        border-color .15s ease;

}


.day-card:hover{

    border-color:
        var(--line-dark);

}


.day-header{

    display:flex;

    align-items:center;

    gap:12px;

    padding:14px;

    border-bottom:
        1px solid
        var(--line);

}


.date-box{

    width:48px;

    min-width:48px;

    overflow:hidden;

    text-align:center;

    border:
        1px solid
        #e6defa;

    border-radius:6px;

    background:
        var(--primary-soft);

    color:
        var(--primary);

}


.date-box .month{

    display:block;

    padding:
        5px 3px 2px;

    font-size:9px;

    font-weight:600;

    text-transform:uppercase;

    letter-spacing:.06em;

}


.date-box .number{

    display:block;

    padding:
        3px 4px 7px;

    font-size:19px;

    line-height:1;

    font-weight:600;

}


.day-title h3{

    margin:
        0 0 3px;

    color:#333039;

    font-size:14px;

    font-weight:600;

}


.day-title p{

    margin:0;

    color:
        var(--muted);

    font-size:12px;

}



/*
|--------------------------------------------------------------------------
| TIME SLOTS
|--------------------------------------------------------------------------
*/

.slots{

    display:grid;

    grid-template-columns:
        repeat(
            2,
            minmax(0,1fr)
        );

    gap:8px;

    padding:14px;

}


.slot{

    position:relative;

    display:block;

}


.slot input{

    position:absolute;

    opacity:0;

    width:1px;

    height:1px;

}


.slot span{

    min-height:46px;

    display:flex;

    align-items:center;

    justify-content:center;

    padding:8px;

    cursor:pointer;

    border:
        1px solid
        var(--line);

    border-radius:6px;

    background:#fff;

    color:#3c3942;

    font-size:13px;

    font-weight:500;

    transition:
        border-color .15s ease,
        background .15s ease,
        color .15s ease;

}


.slot span:hover{

    border-color:#bfb4df;

    background:#faf9fd;

}


.slot input:checked + span{

    border-color:
        var(--primary);

    background:
        var(--primary-soft);

    color:
        var(--primary);

}


.slot.is-disabled{

    pointer-events:none;

    opacity:.55;

}



/*
|--------------------------------------------------------------------------
| ACTIONS
|--------------------------------------------------------------------------
*/

.actions{

    display:flex;

    justify-content:flex-end;

    margin-top:18px;

    padding-top:18px;

    border-top:
        1px solid
        var(--line);

}


.confirm-button{

    min-width:190px;

    min-height:45px;

    display:inline-flex;

    align-items:center;

    justify-content:center;

    border:0;

    border-radius:6px;

    padding:
        12px 18px;

    cursor:pointer;

    background:
        var(--primary);

    color:#fff;

    font-size:13px;

    font-weight:600;

    transition:
        background .15s ease,
        opacity .15s ease;

}


.confirm-button:hover:not(:disabled){

    background:
        var(--primary-hover);

}


.confirm-button:disabled{

    cursor:not-allowed;

    opacity:.45;

}


.btn-loading{

    display:none;

    align-items:center;

    justify-content:center;

    gap:8px;

}


.spinner{

    width:14px;

    height:14px;

    border:
        2px solid
        rgba(255,255,255,.35);

    border-top-color:#fff;

    border-radius:50%;

    animation:
        buttonSpin
        .65s
        linear
        infinite;

}


@keyframes buttonSpin{

    to{
        transform:
            rotate(360deg);
    }

}


.confirm-button.is-loading{

    pointer-events:none;

    opacity:.75;

}


.confirm-button.is-loading
.btn-label{

    display:none;

}


.confirm-button.is-loading
.btn-loading{

    display:flex;

}



/*
|--------------------------------------------------------------------------
| NOTE
|--------------------------------------------------------------------------
*/

.footer-note{

    margin-top:10px;

    color:#99949f;

    text-align:right;

    font-size:11px;

    line-height:1.6;

}



/*
|--------------------------------------------------------------------------
| STATES
|--------------------------------------------------------------------------
*/

.notice,
.success{

    padding:17px;

    border-radius:7px;

    font-size:14px;

    line-height:1.65;

}


.notice{

    background:
        var(--danger-bg);

    border:
        1px solid
        #f2d8de;

    color:
        var(--danger);

}


.success{

    background:
        var(--success-bg);

    border:
        1px solid
        #d5eee2;

    color:
        var(--success);

}


.success strong{

    display:block;

    margin-bottom:5px;

    font-size:17px;

    font-weight:600;

}



/*
|--------------------------------------------------------------------------
| MOBILE
|--------------------------------------------------------------------------
*/

@media(max-width:900px){

    .layout{

        grid-template-columns:1fr;

    }


    .sidebar{

        border-right:0;

        border-bottom:
            1px solid
            var(--line);

    }


    .sidebar h1{

        max-width:none;

    }

}


@media(max-width:650px){

    body{

        padding:12px;

    }


    .topbar{

        height:64px;

        padding:
            0 18px;

    }


    .logo{

        max-width:145px;

        max-height:34px;

    }


    .sidebar,
    .content{

        padding:
            22px 18px;

    }


    .sidebar h1{

        font-size:27px;

    }


    .days{

        grid-template-columns:1fr;

    }

}


@media(max-width:420px){

    .slots{

        grid-template-columns:1fr;

    }


    .actions{

        display:block;

    }


    .confirm-button{

        width:100%;

    }


    .footer-note{

        text-align:left;

    }

}

</style>

</head>


<body>


<main>


<section class="shell">


<header class="topbar">

    <a
        href="https://nonceblox.com/"
        target="_blank"
        rel="noopener"
    >

        <img
            src="https://nonceblox.com/logos.png"
            alt="NonceBlox"
            class="logo"
        >

    </a>

</header>



<div class="layout">



<aside class="sidebar">


    <div class="kicker">

        Interview scheduling

    </div>


    <h1>

        <?= htmlspecialchars(
            ($data['confirmed'] ?? false)
                ? 'Interview confirmed'
                : 'Choose your interview time'
        ) ?>

    </h1>



    <?php if (
        !$error
        &&
        !empty($data['candidate'])
    ): ?>


        <p>

            Hello

            <strong>
                <?= htmlspecialchars(
                    $data['candidate']
                ) ?>
            </strong>.

            Select an available time
            for your

            <strong>
                <?= htmlspecialchars(
                    $data['job'] ?? 'interview'
                ) ?>
            </strong>

            interview.

        </p>


    <?php else: ?>


        <p>

            Select one of the available
            interview slots and confirm
            your preferred time.

        </p>


    <?php endif; ?>



    <div class="calendar">


        <div class="calendar-top">


            <div class="calendar-title">

                Interview calendar

            </div>


            <div class="timezone">

                <?= htmlspecialchars(
                    $data['timezone']
                    ?? 'Timezone'
                ) ?>

            </div>


        </div>



        <div class="calendar-grid">


            <span class="head">S</span>

            <span class="head">M</span>

            <span class="head">T</span>

            <span class="head">W</span>

            <span class="head">T</span>

            <span class="head">F</span>

            <span class="head">S</span>


            <span></span>

            <span></span>

            <span>1</span>

            <span>2</span>

            <span>3</span>

            <span class="active">4</span>

            <span>5</span>


            <span>6</span>

            <span>7</span>

            <span class="active">8</span>

            <span>9</span>

            <span>10</span>

            <span>11</span>

            <span>12</span>


            <span>13</span>

            <span>14</span>

            <span>15</span>

            <span class="active">16</span>

            <span>17</span>

            <span>18</span>

            <span>19</span>


        </div>


    </div>



    <div class="side-info">


        <div class="info">

            <strong>
                Booking
            </strong>

            <span>

                Select one slot and
                confirm your interview time.

            </span>

        </div>



        <div class="info">

            <strong>
                Timezone
            </strong>

            <span>

                <?= htmlspecialchars(
                    $data['timezone']
                    ?? 'Displayed once scheduling data loads.'
                ) ?>

            </span>

        </div>


    </div>


</aside>



<section class="content">



<?php if ($error): ?>


    <div class="notice">

        <?= htmlspecialchars(
            $error
        ) ?>

    </div>



<?php elseif (
    !empty($data['confirmed'])
): ?>


    <div class="success">


        <strong>

            Your interview is confirmed.

        </strong>


        <?php if (
            !empty(
                $data['confirmed_at']
            )
        ): ?>


            <?= htmlspecialchars(

                date(
                    'l, d F Y \a\t g:i A',

                    strtotime(
                        $data['confirmed_at']
                    )
                )

            ) ?>


            <?php if (
                !empty(
                    $data['timezone']
                )
            ): ?>

                (
                <?= htmlspecialchars(
                    $data['timezone']
                ) ?>
                )

            <?php endif; ?>.


        <?php endif; ?>


        This interview link is now closed.


    </div>



<?php elseif (
    !empty($data['expired'])
): ?>


    <div class="notice">

        This interview link has expired.
        Please contact the hiring team
        if you need help.

    </div>



<?php elseif (
    !$slotsByDay
): ?>


    <div class="notice">

        No interview times are
        currently available.

    </div>



<?php else: ?>



<div class="content-header">


    <h2>

        Available time slots

    </h2>


    <p>

        Select the date and time
        that works best for you.

    </p>


</div>



<div class="selection">


    <div class="selection-title">

        Selected slot

    </div>


    <div
        class="selection-text"
        id="selectedSlotText"
    >

        No slot selected

    </div>


</div>



<form
    method="post"
    id="scheduleForm"
>


<input
    type="hidden"
    name="token"
    value="<?= htmlspecialchars(
        $token,
        ENT_QUOTES
    ) ?>"
>



<div class="days">



<?php foreach (
    $slotsByDay
    as $date => $slots
): ?>


<?php

$timestamp =
    strtotime($date);


$month =
    date(
        'M',
        $timestamp
    );


$dayNumber =
    date(
        'd',
        $timestamp
    );


$fullDay =
    date(
        'l',
        $timestamp
    );


$fullDate =
    date(
        'd F Y',
        $timestamp
    );

?>


<section class="day-card">


<div class="day-header">


    <div class="date-box">


        <span class="month">

            <?= htmlspecialchars(
                $month
            ) ?>

        </span>


        <span class="number">

            <?= htmlspecialchars(
                $dayNumber
            ) ?>

        </span>


    </div>



    <div class="day-title">


        <h3>

            <?= htmlspecialchars(
                $fullDay
            ) ?>

        </h3>


        <p>

            <?= htmlspecialchars(
                $fullDate
            ) ?>

        </p>


    </div>


</div>



<div class="slots">



<?php foreach (
    $slots
    as $slot
): ?>


<?php

$timeText =
    date(
        'g:i A',
        strtotime(
            $slot['starts_at']
        )
    );


$labelText =
    $fullDay
    . ', '
    . $fullDate
    . ' at '
    . $timeText;

?>


<label class="slot">


    <input

        type="radio"

        name="slot_id"

        value="<?= (int)(
            $slot['id']
        ) ?>"

        required

        data-label="<?= htmlspecialchars(
            $labelText,
            ENT_QUOTES
        ) ?>"

    >


    <span>

        <?= htmlspecialchars(
            $timeText
        ) ?>

    </span>


</label>


<?php endforeach; ?>


</div>


</section>


<?php endforeach; ?>


</div>



<div class="actions">


<button
    type="submit"
    class="confirm-button"
    id="confirmBtn"
    disabled
>


    <span class="btn-label">

        Confirm interview time

    </span>


    <span class="btn-loading">


        <span class="spinner"></span>


        <span>
            Confirming...
        </span>


    </span>


</button>


</div>



<div class="footer-note">

    Once confirmed, this slot cannot
    be changed from this link.

</div>


</form>


<?php endif; ?>


</section>


</div>


</section>


</main>



<script>

(function () {


    const form =
        document.getElementById(
            'scheduleForm'
        );


    const radios =
        document.querySelectorAll(
            'input[name="slot_id"]'
        );


    const selectedText =
        document.getElementById(
            'selectedSlotText'
        );


    const confirmButton =
        document.getElementById(
            'confirmBtn'
        );


    if (
        !form
        ||
        !radios.length
        ||
        !selectedText
        ||
        !confirmButton
    ) {

        return;

    }



    /*
    |--------------------------------------------------------------------------
    | SLOT SELECT
    |--------------------------------------------------------------------------
    */

    radios.forEach(function (radio) {


        radio.addEventListener(
            'change',
            function () {


                selectedText.textContent =
                    this.getAttribute(
                        'data-label'
                    )
                    || 'Slot selected';


                confirmButton.disabled =
                    false;


            }
        );


    });



    /*
    |--------------------------------------------------------------------------
    | CONFIRM
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {


            const checked =
                document.querySelector(
                    'input[name="slot_id"]:checked'
                );


            if (!checked) {

                event.preventDefault();

                return;

            }



            const confirmation =
                window.confirm(
                    'Confirm this interview time? You cannot change it from this link afterwards.'
                );


            /*
            |--------------------------------------------------------------------------
            | CANCEL
            |--------------------------------------------------------------------------
            */

            if (!confirmation) {

                event.preventDefault();

                return;

            }



            /*
            |--------------------------------------------------------------------------
            | OK CLICKED
            |--------------------------------------------------------------------------
            |
            | From here the form continues submitting.
            | Button immediately becomes disabled
            | and shows loading spinner.
            |
            */


            confirmButton.disabled =
                true;


            confirmButton.classList.add(
                'is-loading'
            );



            radios.forEach(
                function (radio) {


                    radio
                        .closest('.slot')
                        ?.classList.add(
                            'is-disabled'
                        );


                }
            );


        }
    );


})();

</script>


</body>

</html>
