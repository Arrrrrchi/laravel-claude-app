<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email');
            $table->text('bio')->nullable()->after('avatar');
            $table->string('website')->nullable()->after('bio');
            $table->json('social_links')->nullable()->after('website');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('social_links');
            $table->text('two_factor_secret')->nullable()->after('two_factor_confirmed_at');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->boolean('is_admin')->default(false)->after('two_factor_recovery_codes');
            $table->timestamp('last_active_at')->nullable()->after('is_admin');
            
            $table->index('is_admin');
            $table->index('last_active_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_admin']);
            $table->dropIndex(['last_active_at']);
            $table->dropColumn([
                'avatar', 'bio', 'website', 'social_links',
                'two_factor_confirmed_at', 'two_factor_secret', 'two_factor_recovery_codes',
                'is_admin', 'last_active_at'
            ]);
        });
    }
};
