<?php
// PLANTED: the guard value disagrees with config.sample.php -> chk_table_constants
if (!defined('T_SESSIONS')) {
    define('T_SESSIONS', DB_PREFIX . 'sessions_TYPO');
}
