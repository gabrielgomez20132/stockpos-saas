<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CategoriaController extends Controller
{
    private function listado(bool $activas)
    {
        return Inertia::render('Categorias/Index', [
            'categorias' => ProductCategory::withCount(['productos' => fn ($q) => $q->where('activo', 1)])
                ->where('activa', $activas)->orderBy('nombre')->get(),
            'activas' => $activas,
        ]);
    }

    public function index()
    {
        return $this->listado(true);
    }

    public function papelera()
    {
        return $this->listado(false);
    }

    private function reglas(?ProductCategory $c = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:25', Rule::unique('product_categories', 'nombre')->ignore($c?->id)],
            'unidad' => ['required', Rule::in(ProductCategory::UNIDADES)],
        ];
    }

    public function create()
    {
        return Inertia::render('Categorias/Form', ['categoria' => null, 'unidades' => ProductCategory::UNIDADES]);
    }

    public function store(Request $request)
    {
        ProductCategory::create($request->validate($this->reglas()) + ['activa' => 1]);

        return redirect()->route('categorias.index')->with('ok', 'Categoría creada.');
    }

    public function edit(ProductCategory $categoria)
    {
        return Inertia::render('Categorias/Form', ['categoria' => $categoria->only(['id', 'nombre', 'unidad']), 'unidades' => ProductCategory::UNIDADES]);
    }

    public function update(Request $request, ProductCategory $categoria)
    {
        $categoria->update($request->validate($this->reglas($categoria)));

        return redirect()->route('categorias.index')->with('ok', 'Categoría actualizada.');
    }

    public function destroy(ProductCategory $categoria)
    {
        $categoria->update(['activa' => 0]);

        return $this->ok('Categoría "'.$categoria->nombre.'" en la papelera.');
    }

    public function restaurar(ProductCategory $categoria)
    {
        $categoria->update(['activa' => 1]);

        return $this->ok('Categoría recuperada.');
    }
}
