<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Backfill images from image
        $rows = DB::table('products')->select('id','image','images')->get();
        foreach ($rows as $row) {
            $images = $row->images;
            $image = $row->image;
            $array = [];
            if (is_string($images) && $images !== '') {
                // if incorrectly stored as string, wrap into array
                $array[] = $images;
            } elseif (is_array($images)) {
                $array = $images;
            }
            if (is_string($image) && $image !== '') {
                array_unshift($array, $image);
            }
            $array = array_values(array_unique(array_filter($array, fn($v) => is_string($v) && $v !== '')));
            if (!empty($array)) {
                DB::table('products')->where('id', $row->id)->update(['images' => json_encode($array)]);
            }
        }

        // Drop legacy image column
        if (Schema::hasColumn('products', 'image')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('image');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate image column (nullable) and backfill from first images element
        if (!Schema::hasColumn('products', 'image')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('image')->nullable()->after('stock');
            });
        }
        $rows = DB::table('products')->select('id','images')->get();
        foreach ($rows as $row) {
            $first = null;
            if (is_array($row->images) && !empty($row->images)) {
                $first = $row->images[0];
            }
            if (is_string($first) && $first !== '') {
                DB::table('products')->where('id', $row->id)->update(['image' => $first]);
            }
        }
    }
};
