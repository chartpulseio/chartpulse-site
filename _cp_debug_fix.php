<?php
require __DIR__.'/torus_engine.php';
require_once __DIR__.'/torus_tier2.php';
// Just confirm the source on disk (and thus what's live) has the new logic, by reflecting the function body.
$src = file_get_contents(__DIR__.'/torus_engine.php');
$has = strpos($src, 'count($tkArr) - 1') !== false && strpos($src, 'count($kTk) - 1') !== false;
echo json_encode(['fix_present' => $has]);
