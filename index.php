<?php
require_once __DIR__ . '/app/Config/init.php';

if ($isLoggedIn && $isProviderOrAdmin) {
    redirect('AdminView/admin_dashboard.php');
}

redirect('View/index.php');
