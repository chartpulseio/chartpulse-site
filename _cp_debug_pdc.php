<?php
require __DIR__.'/torus_engine.php';
require_once __DIR__.'/torus_tier2.php';
ini_set('display_errors','1');
$sym = 'EURUSD=X';
$fr = t_load_frames($sym);
$out = ['fr_null' => $fr === null, 'keys' => $fr ? array_keys($fr) : null];
if ($fr && isset($fr['1D'])) {
    $out['1D_keys'] = array_keys($fr['1D']);
    $out['1D_c_count'] = isset($fr['1D']['c']) ? count($fr['1D']['c']) : 'no c key';
}
echo json_encode($out);
