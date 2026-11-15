<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/../strategy_page.php';
strat_scanner(basename(__DIR__), $SCANNERS, (string)($_GET['id'] ?? ''));
