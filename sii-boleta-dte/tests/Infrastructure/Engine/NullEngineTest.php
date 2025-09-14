<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\NullEngine;

class NullEngineTest extends TestCase {
    public function test_methods_return_false() {
        $engine = new NullEngine();
        $this->assertFalse($engine->generate_dte_xml([], 0, false));
        $this->assertFalse($engine->render_pdf('<xml/>'));
    }
}
