<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add role_mention column to structure_manager_webhooks table
 * 
 * This allows each webhook to have its own Discord role mention,
 * enabling different role pings for different corporations/channels
 */
class AddRoleMentionToWebhooks extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('structure_manager_webhooks', function (Blueprint $table) {
            $table->string('role_mention', 100)->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('structure_manager_webhooks', function (Blueprint $table) {
            $table->dropColumn('role_mention');
        });
    }
}
