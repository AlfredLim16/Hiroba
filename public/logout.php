<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';

// Terminate user session and clear security tokens
logoutUser();

setFlash('info', 'You have been safely signed out of Hiroba.');
redirect('login.php');
