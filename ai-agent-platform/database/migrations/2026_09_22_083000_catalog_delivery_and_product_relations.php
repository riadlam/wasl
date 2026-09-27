<?php

use App\Support\AlgerianWilayas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wilayas')) {
            Schema::create('wilayas', function (Blueprint $table) {
                $table->id();
                $table->string('code', 2)->unique();
                $table->string('name_fr');
                $table->string('name_ar');
                $table->timestamps();
            });
        }

        $now = now();
        if (DB::table('wilayas')->count() === 0) {
            foreach (AlgerianWilayas::all() as $row) {
                DB::table('wilayas')->insert([
                    'code' => $row['code'],
                    'name_fr' => $row['name_fr'],
                    'name_ar' => $row['name_ar'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (! Schema::hasColumn('delivery_zones', 'name')) {
            Schema::table('delivery_zones', function (Blueprint $table) {
                $table->string('name')->nullable()->after('business_id');
            });
        }

        if (! Schema::hasTable('delivery_zone_wilayas')) {
            Schema::create('delivery_zone_wilayas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
                $table->foreignId('wilaya_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['delivery_zone_id', 'wilaya_id']);
            });
        }

        if (Schema::hasColumn('delivery_zones', 'wilaya')) {
            $wilayas = DB::table('wilayas')->get();
            foreach (DB::table('delivery_zones')->get() as $zone) {
                $name = trim((string) ($zone->wilaya ?? ''));
                if (trim((string) ($zone->name ?? '')) === '') {
                    DB::table('delivery_zones')->where('id', $zone->id)->update([
                        'name' => $name !== '' ? $name : 'Zone '.$zone->id,
                    ]);
                }
                $match = $this->matchWilaya($wilayas, $name);
                if ($match && ! DB::table('delivery_zone_wilayas')
                    ->where('delivery_zone_id', $zone->id)
                    ->where('wilaya_id', $match->id)
                    ->exists()) {
                    DB::table('delivery_zone_wilayas')->insert([
                        'delivery_zone_id' => $zone->id,
                        'wilaya_id' => $match->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $this->dropLegacyWilayaColumn();
        }

        if (! Schema::hasColumn('products', 'type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('type')->default('physical')->after('status');
                $table->string('channel_scope')->default('all')->after('type');
            });
        }

        if (! Schema::hasTable('product_images')) {
            Schema::create('product_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('path');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_main')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_channels')) {
            Schema::create('product_channels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['product_id', 'social_account_id']);
            });
        }

        if (! Schema::hasTable('product_delivery_zones')) {
            Schema::create('product_delivery_zones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['product_id', 'delivery_zone_id']);
            });
        }

        if (! Schema::hasTable('product_digital_assets')) {
            Schema::create('product_digital_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete()->unique();
                $table->string('delivery_note')->nullable();
                $table->string('access_url')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_digital_assets');
        Schema::dropIfExists('product_delivery_zones');
        Schema::dropIfExists('product_channels');
        Schema::dropIfExists('product_images');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['type', 'channel_scope']);
        });

        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->string('wilaya')->nullable()->after('business_id');
        });

        foreach (DB::table('delivery_zones')->get() as $zone) {
            $first = DB::table('delivery_zone_wilayas')
                ->where('delivery_zone_id', $zone->id)
                ->join('wilayas', 'wilayas.id', '=', 'delivery_zone_wilayas.wilaya_id')
                ->value('wilayas.name_fr');
            DB::table('delivery_zones')->where('id', $zone->id)->update([
                'wilaya' => $first ?: $zone->name,
            ]);
        }

        Schema::dropIfExists('delivery_zone_wilayas');

        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->unique(['business_id', 'wilaya']);
        });

        Schema::dropIfExists('wilayas');
    }

    private function dropLegacyWilayaColumn(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
        });

        Schema::table('delivery_zones', function (Blueprint $table) {
            foreach (Schema::getIndexes('delivery_zones') as $index) {
                if (($index['unique'] ?? false)
                    && ! ($index['primary'] ?? false)
                    && in_array('wilaya', $index['columns'] ?? [], true)
                ) {
                    $table->dropUnique($index['name']);
                    break;
                }
            }
            $table->dropColumn('wilaya');
        });

        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }

    private function matchWilaya(object $wilayas, string $name): ?object
    {
        $needle = $this->fold($name);
        if ($needle === '') {
            return null;
        }

        foreach ($wilayas as $wilaya) {
            if ($this->fold($wilaya->name_fr) === $needle
                || $this->fold($wilaya->name_ar) === $needle
                || $wilaya->code === $name
                || ltrim($wilaya->code, '0') === ltrim($name, '0')
            ) {
                return $wilaya;
            }
        }

        $aliases = [
            'algiers' => 'alger',
            'alger centre' => 'alger',
            'setif' => 'setif',
            'bejaia' => 'bejaia',
            'bechar' => 'bechar',
        ];
        $aliased = $aliases[$needle] ?? $needle;
        foreach ($wilayas as $wilaya) {
            if ($this->fold($wilaya->name_fr) === $aliased) {
                return $wilaya;
            }
        }

        return null;
    }

    private function fold(string $value): string
    {
        $value = Str::lower(trim($value));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $ascii !== false ? strtolower($ascii) : $value;
    }
};
