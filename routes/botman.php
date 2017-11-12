<?php

$botman = resolve('botman');

// routes should exclusively work (listen) in channels
$botman->hears('help[ ]*', \App\Http\Controllers\MiaBotController::class.'@help');
$botman->hears('play mia.*', \App\Http\Controllers\MiaBotController::class.'@handle');
$botman->hears('close mia', \App\Http\Controllers\MiaBotController::class.'@close');
$botman->hears('me', \App\Http\Controllers\MiaBotController::class.'@join');
$botman->hears('leave', \App\Http\Controllers\MiaBotController::class.'@leave');
$botman->hears('start game', \App\Http\Controllers\MiaBotController::class.'@handle');

// routes should exclusively work in the private message between the bot and the user
$botman->hears('([1-9]{0,1}[0-9]+(,|.)[0-6]|small mia|mia)', \App\Http\Controllers\MiaBotController::class.'@handle');
$botman->hears('shake[ ]*', \App\Http\Controllers\MiaBotController::class.'@handle');
$botman->hears('liar[ ]*', \App\Http\Controllers\MiaBotController::class.'@handle');
$botman->hears('abort game', \App\Http\Controllers\MiaBotController::class.'@handle');
$botman->hears('say .+', \App\Http\Controllers\MiaBotController::class.'@handle');
