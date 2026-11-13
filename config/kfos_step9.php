<?php

/**
 * Step 9 is an INERT integration foundation. No route or production HR writer
 * may enable it until authorization, source-event provenance, atomic HR
 * transition, globally protected sessions, clearance and recovery are proven.
 * Synthetic kfos_test feature tests opt in only in their own PHP process.
 */
return [
    'hr_revocation_coordinator_enabled' => false,
];
