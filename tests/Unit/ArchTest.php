<?php

arch('no debug statements leak into the package')
    ->expect('DeptOfScrapyardRobotics\Displays\SDL3')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray', 'print_r']);

arch('the driver extends the framework display base')
    ->expect('DeptOfScrapyardRobotics\Displays\SDL3\SDL3Display')
    ->toExtend('BareMetal\Displays\Display');

arch('op codes are a backed enum')
    ->expect('DeptOfScrapyardRobotics\Displays\SDL3\Enums\SDL3OpCode')
    ->toBeEnums();
