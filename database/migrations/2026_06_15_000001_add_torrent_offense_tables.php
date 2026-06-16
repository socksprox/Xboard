<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->string('torrent_mode', 16)->nullable()->after('enabled')
                ->comment('Synced from node/Ansible: allow|block|report');
        });

        Schema::create('v2_user_restriction', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('scope', 32)->comment('node|strict_pool|global');
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('offense_id')->nullable();
            $table->unsignedInteger('expires_at')->nullable()->index();
            $table->unsignedInteger('revoked_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');

            $table->index(['user_id', 'revoked_at', 'expires_at']);
        });

        Schema::create('v2_user_offense', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('server_id')->index();
            $table->string('type', 32)->default('torrent');
            $table->json('detail')->nullable();
            $table->string('action_applied', 64)->nullable();
            $table->unsignedBigInteger('restriction_id')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');

            $table->index(['user_id', 'created_at']);
        });

        $defaultPolicy = json_encode([
            'enabled' => true,
            'action' => 'node_suspend',
            'duration_hours' => 24,
        ], JSON_UNESCAPED_UNICODE);

        if (!DB::table('v2_settings')->where('name', 'torrent_offense_policy')->exists()) {
            DB::table('v2_settings')->insert([
                'group' => 'torrent',
                'type' => 'json',
                'name' => 'torrent_offense_policy',
                'value' => $defaultPolicy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('v2_settings')
            ->where('name', 'torrent_offense_policy')
            ->delete();

        Schema::dropIfExists('v2_user_offense');
        Schema::dropIfExists('v2_user_restriction');

        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn('torrent_mode');
        });
    }
};
