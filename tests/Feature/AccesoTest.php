<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AccesoTest extends TestCase
{
    public function test_entra_con_email_y_con_dni(): void
    {
        $this->post('/login', ['usuario' => 'admin@stokpos.local', 'password' => 'admin'])->assertRedirect('/inicio');
        $this->post('/logout');
        $this->post('/login', ['usuario' => '1234', 'password' => 'admin'])->assertRedirect('/inicio');
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_clave_incorrecta_y_usuario_dado_de_baja(): void
    {
        $this->post('/login', ['usuario' => '1234', 'password' => 'mala'])->assertSessionHasErrors('usuario');
        $e = $this->encargado();
        $e->update(['activo' => 0]);
        $this->post('/login', ['usuario' => (string) $e->dni, 'password' => 'secreto1'])->assertSessionHasErrors('usuario');
        $this->assertGuest();
    }

    public function test_un_cliente_no_puede_entrar_aunque_tenga_clave(): void
    {
        User::create(['nombre' => 'Cli', 'dni' => 777, 'password' => 'secreto1', 'id_uType' => User::TIPO_CLIENTE, 'activo' => 1]);
        $this->post('/login', ['usuario' => '777', 'password' => 'secreto1'])->assertSessionHasErrors('usuario');
    }

    public function test_limita_intentos_de_login(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['usuario' => '1234', 'password' => 'x']);
        }
        $this->post('/login', ['usuario' => '1234', 'password' => 'admin'])->assertStatus(429);
    }

    public function test_sin_sesion_redirige_al_login(): void
    {
        $this->get('/inicio')->assertRedirect('/login');
        $this->getJson('/buscar/productos?q=a')->assertStatus(401);
    }

    public function test_empleado_no_entra_a_secciones_sin_permiso(): void
    {
        $emp = $this->encargado('Empleado');
        $this->actingAs($emp);
        $this->get('/ventas')->assertOk();
        $this->get('/clientes')->assertOk();
        foreach (['/productos', '/categorias', '/caja/movimientos', '/caja/gastos/varios', '/afip', '/afip/configuracion', '/roles', '/encargados', '/devoluciones', '/proveedores', '/ventas/exportar?formato=csv'] as $url) {
            $this->get($url)->assertForbidden();
        }
        // y tampoco puede cargar un gasto aunque arme el POST a mano
        $this->abrirCaja();
        $this->post('/caja/movimientos', ['id_desc' => 3, 'monto' => 100])->assertForbidden();
    }

    public function test_super_admin_ve_todas_las_pantallas(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto();
        $c = $this->cliente();
        $prov = User::create(['nombre' => 'Prov', 'id_uType' => User::TIPO_PROVEEDOR, 'activo' => 1]);
        $urls = ['/inicio', '/perfil', '/caja', '/caja/retiros', '/caja/gastos/varios', '/caja/gastos/proveedores', '/caja/movimientos', '/caja/cierres',
            '/ventas', '/ventas?desde=2026-01-01&hasta=2026-12-31', '/devoluciones', '/devoluciones/crear', '/clientes', '/clientes/papelera', '/clientes/create',
            "/clientes/{$c->id}/edit", "/clientes/{$c->id}/historial", '/encargados', '/encargados/create', '/proveedores', '/proveedores/create',
            "/proveedores/{$prov->id}/cuenta", '/productos', '/productos?q=Prod&stock=bajo', '/productos/create', "/productos/{$p->id}/edit",
            '/categorias', '/categorias/create', '/reportes', '/afip', '/afip/configuracion', '/roles', '/caja/movimientos?desde=2026-01-01&hasta=2026-12-31'];
        foreach ($urls as $u) {
            $this->get($u)->assertOk();
        }
        $this->getJson('/reportes/datos?periodo=semana')->assertOk()->assertJsonStructure(['porDia', 'top', 'medios', 'totales']);
    }

    public function test_headers_de_seguridad(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_cambio_de_clave(): void
    {
        $this->actingAs($this->admin);
        $this->put('/perfil/clave', ['actual' => 'mala', 'password' => 'nueva123', 'password_confirmation' => 'nueva123'])->assertSessionHasErrors('actual');
        $this->put('/perfil/clave', ['actual' => 'admin', 'password' => 'nueva123', 'password_confirmation' => 'nueva123'])->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->post('/login', ['usuario' => '1234', 'password' => 'nueva123'])->assertRedirect('/inicio');
    }
}
