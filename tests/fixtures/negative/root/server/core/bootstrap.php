<?php
declare(strict_types=1);
// 하위호환 가드. 값은 config.sample.php 와 같아야 한다.
if (!defined('T_SESSIONS')) {
    define('T_SESSIONS', DB_PREFIX . 'sessions');
}
