<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_public_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        if (! Schema::hasColumn('auth_otps', 'attempts')) {
            Schema::table('auth_otps', function (Blueprint $table) {
                $table->unsignedSmallInteger('attempts')->default(0);
            });
        }

        if (! Schema::hasColumn('crop_deals', 'payment_ref')) {
            Schema::table('crop_deals', function (Blueprint $table) {
                $table->string('payment_ref')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');

        if (Schema::hasColumn('auth_otps', 'attempts')) {
            Schema::table('auth_otps', fn (Blueprint $table) => $table->dropColumn('attempts'));
        }

        if (Schema::hasColumn('crop_deals', 'payment_ref')) {
            Schema::table('crop_deals', fn (Blueprint $table) => $table->dropColumn('payment_ref'));
        }
    }
};
