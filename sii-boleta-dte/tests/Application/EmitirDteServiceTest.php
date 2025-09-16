<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Application\EmitirDteService;
use Sii\BoletaDte\Domain\DteRepository;

class EmitirDteServiceTest extends TestCase {
    public function test_requires_id_and_data() {
        $repo = $this->createMock(DteRepository::class);
        $service = new EmitirDteService($repo);
        $this->expectException(\InvalidArgumentException::class);
        $service('', []);
    }

    public function test_wraps_repository_exception() {
        $repo = $this->createMock(DteRepository::class);
        $repo->method('save')->willThrowException(new \Exception('fail'));
        $service = new EmitirDteService($repo);
        $this->expectException(\RuntimeException::class);
        $service('id', ['a' => 'b']);
    }
}
