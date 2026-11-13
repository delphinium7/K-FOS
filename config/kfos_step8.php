<?php

/*
 * Step 8 epoch admission is compiled OFF. Its activation requires a separate
 * reviewed release/migration/backfill and real HR event integration.
 * There is deliberately no .env switch that can enable this by accident.
 * Synthetic kfos_test suites can opt in with config([...]) in their own process.
 */
return [
    'epoch_admission_enabled' => false,
];
