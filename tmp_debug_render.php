<?php
require __DIR__ . '/vendor/autoload.php';
// Bootstrap test helpers similar to tests/bootstrap.php
require __DIR__ . '/tests/bootstrap.php';

use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\QueueProcessor;
use Sii\BoletaDte\Presentation\Admin\ControlPanelPage;

// prepare environment
QueueDb::purge();
$id = QueueDb::enqueue('dte', ['file'=>'x']);

$settings = new class extends Settings {
    public function get_settings() { return []; }
    public function get_environment() { return '0'; }
};
$folio_manager = new FolioManager($settings);
$processor = new QueueProcessor(new Sii\BoletaDte\Infrastructure\Rest\Api());
$api = new Sii\BoletaDte\Infrastructure\Rest\Api();
$token_manager = new Sii\BoletaDte\Infrastructure\WordPress\TokenManager($api, $settings);

$page = new ControlPanelPage($settings, $folio_manager, $processor, $api, $token_manager);
ob_start();
$page->render_page();
$out = ob_get_clean();
file_put_contents(__DIR__.'/tmp_render_out.html', $out);

echo "Wrote tmp_render_out.html\n";

echo substr($out,0,500);
