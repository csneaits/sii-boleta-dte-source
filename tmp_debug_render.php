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
$folio = $this->createMock ?? null; // placeholder; we won't use it
$processor = new QueueProcessor(new class implements Sii\BoletaDte\Infrastructure\Rest\Api {
});

// The ControlPanelPage constructor in tests expects many dependencies; create simple mocks
$rvd = new class { public function generate_xml(){return '<ConsumoFolios />';} public function validate_rvd_xml($x){return true;} };
$libro = new class { public function validate_libro_xml($x){return true;} };
$api = new class { };
$token_manager = new class { public function get_token($e){ return 't'; } };

$page = new ControlPanelPage($settings, new FolioManager($settings), $processor, $rvd, $libro, $api, $token_manager);
ob_start();
$page->render_page();
$out = ob_get_clean();
file_put_contents(__DIR__.'/tmp_render_out.html', $out);

echo "Wrote tmp_render_out.html\n";

echo substr($out,0,500);
