<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure-fitting doctrines for Structure Manager's Doctrine Compliance page.
 * Ported from HR Manager (identical design). Each row is one recommended Upwell
 * structure fit, keyed by (scope, structure_type, security_band):
 *   - scope_type/scope_id: per-corp or per-alliance (a setting picks the mode;
 *     scope_id holds the corporation_id or alliance_id accordingly).
 *   - structure_type_id: the Upwell hull (parsed from the EFT [Hull, Name]).
 *   - security_band: highsec / lowsec / nullsec / wormhole (WH = J###### system).
 *
 * The recommended fit is pasted as EFT (eft_raw) and parsed into `parsed`
 * (hull + required fitted modules + informational cargo/fighter lines).
 * Compliance compares a structure's actual rigs + service modules + modules
 * (corporation_assets via CorporationStructure) against `parsed`. Reads only
 * SeAT core; standalone-safe.
 */
class CreateStructureManagerDoctrines extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('structure_manager_doctrines')) {
            return;
        }

        Schema::create('structure_manager_doctrines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('scope_type', ['corp', 'alliance'])->default('corp');
            $table->unsignedBigInteger('scope_id');             // corporation_id or alliance_id
            $table->integer('structure_type_id');               // Upwell hull type (from EFT)
            $table->string('structure_type_name')->nullable();  // denormalized for display
            $table->enum('security_band', ['highsec', 'lowsec', 'nullsec', 'wormhole']);
            $table->string('name');
            $table->text('eft_raw');
            $table->json('parsed');
            $table->boolean('require_fighters')->default(false);
            $table->boolean('require_ammo')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // One doctrine per (scope, structure type, band).
            $table->unique(['scope_type', 'scope_id', 'structure_type_id', 'security_band'], 'sm_doctrine_scope_uniq');
            $table->index('structure_type_id');
            $table->index('scope_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_manager_doctrines');
    }
}
