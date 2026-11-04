<?php
require __DIR__.'/torus_engine.php';
require_once __DIR__.'/torus_tier2.php';
ini_set('display_errors','1');
$sym = 'EURUSD=X';
$d = t2_build($sym, 'PUT');
$out = ['error' => $d['error'] ?? null, 'zone' => $d['zone'] ?? null];
echo json_encode($out);
