<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use Tests\TestCase;

class EtiquetasTest extends TestCase
{
    private function cfg(array $c = []): array
    {
        return $c + \App\Http\Controllers\EtiquetaController::DEFECTO;
    }

    public function test_elegir_imprimir_y_excel(): void
    {
        $this->actingAs($this->admin);
        $a = $this->producto(['codigo' => '7790895000997', 'nombre' => 'Coca 500', 'monto' => 1500]);
        $b = $this->producto(['codigo' => 'ALF-1', 'nombre' => 'Alfajor', 'monto' => 800]);

        $this->get("/etiquetas?ids={$a->id},{$b->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Etiquetas/Index', false)->has('seleccion', 2)->where('config.ancho', 40)->etc());
        $this->assertCount(\App\Models\Product::where('activo', 1)->where('tiene_variantes', 0)->count(), $this->getJson('/etiquetas/buscar?catalogo=1')->assertOk()->json());
        $this->getJson('/etiquetas/buscar?q=alfa')->assertOk()->assertJsonPath('0.id', $b->id);
        $this->get('/etiquetas/barra?codigo=ALF-1')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        $items = [['id' => $a->id, 'copias' => 3], ['id' => $b->id, 'copias' => 2]];
        $this->postJson('/etiquetas/preparar', ['items' => $items, 'config' => $this->cfg(['ancho' => 50, 'alto' => 25, 'formato' => 'hoja'])])
            ->assertOk()->assertJsonStructure(['url']);
        $this->get('/etiquetas/imprimir')->assertOk()->assertInertia(fn ($p) => $p->component('Print/Etiquetas', false)
            ->has('etiquetas', 5)->where('config.ancho', 50)->where('config.formato', 'hoja')->etc());
        Configuracion::limpiarCache();
        $this->assertSame(50, (int) Configuracion::get('etiquetas')['ancho']); // queda guardada

        $pdf = $this->postJson('/etiquetas/pdf', ['items' => $items, 'config' => $this->cfg()])->assertOk()->json();
        $this->assertCount(5, $pdf['etiquetas']);
        $this->assertGreaterThan(10, count($pdf['barras']['7790895000997']['barras']));
        $this->assertGreaterThan(0, $pdf['barras']['ALF-1']['total']);

        $this->postJson('/etiquetas/preparar', ['items' => array_map(fn ($i) => ['id' => $a->id + $i, 'copias' => 999], range(0, 10)), 'config' => $this->cfg()])->assertStatus(422); // mas de 10.000 etiquetas
        $this->postJson('/etiquetas/preparar', ['items' => [], 'config' => $this->cfg()])->assertStatus(422);

        $this->actingAs($this->encargado('Empleado'));
        $this->get('/etiquetas')->assertForbidden();
    }
}
