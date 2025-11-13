<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Product::query()->orderByDesc('id')->get();
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $rules = [
            'name' => ['required','string','max:255'],
            'category' => ['nullable','string','max:255'],
            'price_number' => ['required','numeric','min:0'],
            'price_label' => ['nullable','string','max:255'],
            'stock' => ['nullable','integer','min:0'],
            'sku' => ['nullable','string','max:255'],
            'description' => ['nullable','string'],
            'specifications' => ['nullable','array'],
            'images' => ['nullable','array'],
        ];
        // no single image field; use images[]
        $data = $request->validate($rules);
        if (empty($data['category'])) {
            $data['category'] = 'General';
        }

        // build images array from files and string inputs

        $storedImages = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                if ($file) {
                    $storedImages[] = $file->store('products', 'public');
                }
            }
        }
        $inputImages = $request->input('images', []);
        $inputImages = is_array($inputImages) ? $inputImages : [];
        $allImages = array_values(array_unique(array_filter(array_merge($inputImages, $storedImages), fn($v) => is_string($v) && $v !== '')));
        if (!empty($allImages)) {
            $data['images'] = $allImages;
        }

        $product = Product::create($data);
        return response()->json($product, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        return $product;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product $product)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        $rules = [
            'name' => ['sometimes','string','max:255'],
            'category' => ['sometimes','string','max:255'],
            'price_number' => ['sometimes','numeric','min:0'],
            'price_label' => ['nullable','string','max:255'],
            'stock' => ['nullable','integer','min:0'],
            'sku' => ['nullable','string','max:255'],
            'description' => ['nullable','string'],
            'specifications' => ['nullable','array'],
            'images' => ['nullable','array'],
        ];
        // no single image field; use images[]
        $data = $request->validate($rules);
        if (array_key_exists('category', $data) && empty($data['category'])) {
            $data['category'] = 'General';
        }

        // build images array

        $storedImages = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                if ($file) {
                    $storedImages[] = $file->store('products', 'public');
                }
            }
        }
        $inputImages = $request->input('images', []);
        $inputImages = is_array($inputImages) ? $inputImages : [];
        $allImages = array_values(array_unique(array_filter(array_merge($inputImages, $storedImages), fn($v) => is_string($v) && $v !== '')));
        if (!empty($allImages)) {
            $data['images'] = $allImages;
        }

        $product->update($data);
        return $product;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $product->delete();
        return response()->noContent();
    }
}
