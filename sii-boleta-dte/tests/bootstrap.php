<?php
define('ABSPATH', __DIR__.'/../');
// Constants for plugin
define('SII_BOLETA_DTE_PATH', __DIR__.'/../');
define('SII_BOLETA_DTE_URL', 'http://example.com/');
define('SII_BOLETA_DTE_VERSION', 'test');
require SII_BOLETA_DTE_PATH . 'src/includes/autoload.php';
if ( file_exists( SII_BOLETA_DTE_PATH . 'vendor/autoload.php' ) ) {
    require SII_BOLETA_DTE_PATH . 'vendor/autoload.php';
}
