<?php
// PLANTED: no front-end file calls /api/ghost/orphan  -> chk_endpoints
// PLANTED: 'nobody_reads_this' appears in no screen   -> chk_unconsumed_response_fields
json_out(['nobody_reads_this' => 1, 'items' => []]);
