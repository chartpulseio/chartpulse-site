<?php
$src = file_get_contents(__DIR__.'/torus_engine.php');
echo json_encode([
    'has_isMt_override' => strpos($src, "\$setup = 'midtrend'") !== false,
    'has_mt_in_slim' => strpos($src, "'lad','sl_wide','mt'") !== false,
]);
