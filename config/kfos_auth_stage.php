<?php

return [
    // Disabled unless the local developer explicitly opts in.
    'enabled' => filter_var(env('KFOS_AUTH_STAGE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
