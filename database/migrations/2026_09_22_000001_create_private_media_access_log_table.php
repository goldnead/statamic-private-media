<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('private-media.audit.table', 'private_media_access_log');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            // A string without foreign key: Statamic users may be flat files,
            // and a deleted user's trail should outlive the user.
            $table->string('user_id', 191)->nullable()->index();
            $table->string('resource', 191);
            $table->string('path', 1024)->nullable();
            $table->boolean('allowed');
            $table->string('reason', 40);
            $table->string('delivery', 20)->nullable();
            $table->string('range', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['resource', 'created_at']);
            $table->index(['allowed', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('private-media.audit.table', 'private_media_access_log'));
    }
};
