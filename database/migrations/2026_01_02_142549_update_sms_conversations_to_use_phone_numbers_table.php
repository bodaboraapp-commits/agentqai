<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The table this migration rewrites.
     */
    private string $table = 'sms_conversations';

    /**
     * Run the migrations.
     *
     * Every step is guarded: shared hosts can kill the PHP process after MySQL
     * has already executed a DDL statement but before the migration is
     * recorded, and a plain re-run then dies on
     * "1091 Can't DROP FOREIGN KEY ... check that it exists". Checking the
     * live schema first means a retry resumes from whatever state the
     * interrupted attempt left behind instead of failing forever.
     */
    public function up(): void
    {
        $oldForeignKey = 'sms_conversations_sms_phone_number_id_foreign';
        $oldIndexes = [
            'sms_conversations_sms_phone_number_id_status_index',
            'sms_conversations_contact_phone_sms_phone_number_id_index',
        ];
        $newForeignKey = 'sms_conversations_phone_number_id_foreign';
        $newIndexes = [
            'sms_conversations_phone_number_id_status_index' => ['phone_number_id', 'status'],
            'sms_conversations_contact_phone_phone_number_id_index' => ['contact_phone', 'phone_number_id'],
        ];

        $foreignKeys = collect(Schema::getForeignKeys($this->table))->pluck('name')->all();
        $indexes = collect(Schema::getIndexes($this->table))->pluck('name')->all();
        $columns = Schema::getColumnListing($this->table);

        // 1. Remove the old column and everything that depends on it. MySQL
        //    must lose the foreign key before its index, and the index before
        //    the column.
        Schema::table($this->table, function (Blueprint $table) use ($foreignKeys, $indexes, $oldForeignKey, $oldIndexes) {
            if (in_array($oldForeignKey, $foreignKeys, true)) {
                $table->dropForeign($oldForeignKey);
            }

            foreach ($oldIndexes as $index) {
                if (in_array($index, $indexes, true)) {
                    $table->dropIndex($index);
                }
            }

            if (Schema::hasColumn('sms_conversations', 'sms_phone_number_id')) {
                $table->dropColumn('sms_phone_number_id');
            }
        });

        // 2. Add the replacement column, its foreign key, and its indexes only
        //    if the interrupted attempt did not already get there.
        if (! Schema::hasColumn($this->table, 'phone_number_id')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->foreignId('phone_number_id')->after('id')->constrained('phone_numbers')->onDelete('cascade');
            });
        } else {
            $foreignKeys = collect(Schema::getForeignKeys($this->table))->pluck('name')->all();

            if (! in_array($newForeignKey, $foreignKeys, true)) {
                Schema::table($this->table, function (Blueprint $table) {
                    $table->foreign('phone_number_id')->references('id')->on('phone_numbers')->onDelete('cascade');
                });
            }
        }

        $indexes = collect(Schema::getIndexes($this->table))->pluck('name')->all();

        foreach ($newIndexes as $name => $columnsForIndex) {
            if (! in_array($name, $indexes, true)) {
                Schema::table($this->table, function (Blueprint $table) use ($columnsForIndex) {
                    $table->index($columnsForIndex);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * Guarded the same way for the same reason: a rollback that is cut off
     * half-way must be resumable.
     */
    public function down(): void
    {
        $newForeignKey = 'sms_conversations_phone_number_id_foreign';
        $newIndexes = [
            'sms_conversations_phone_number_id_status_index',
            'sms_conversations_contact_phone_phone_number_id_index',
        ];
        $oldForeignKey = 'sms_conversations_sms_phone_number_id_foreign';
        $oldIndexes = [
            'sms_conversations_sms_phone_number_id_status_index' => ['sms_phone_number_id', 'status'],
            'sms_conversations_contact_phone_sms_phone_number_id_index' => ['contact_phone', 'sms_phone_number_id'],
        ];

        $foreignKeys = collect(Schema::getForeignKeys($this->table))->pluck('name')->all();
        $indexes = collect(Schema::getIndexes($this->table))->pluck('name')->all();

        Schema::table($this->table, function (Blueprint $table) use ($foreignKeys, $indexes, $newForeignKey, $newIndexes) {
            if (in_array($newForeignKey, $foreignKeys, true)) {
                $table->dropForeign($newForeignKey);
            }

            foreach ($newIndexes as $index) {
                if (in_array($index, $indexes, true)) {
                    $table->dropIndex($index);
                }
            }

            if (Schema::hasColumn('sms_conversations', 'phone_number_id')) {
                $table->dropColumn('phone_number_id');
            }
        });

        if (! Schema::hasColumn($this->table, 'sms_phone_number_id')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->foreignId('sms_phone_number_id')->after('id')->constrained('sms_phone_numbers')->onDelete('cascade');
            });
        } else {
            $foreignKeys = collect(Schema::getForeignKeys($this->table))->pluck('name')->all();

            if (! in_array($oldForeignKey, $foreignKeys, true)) {
                Schema::table($this->table, function (Blueprint $table) {
                    $table->foreign('sms_phone_number_id')->references('id')->on('sms_phone_numbers')->onDelete('cascade');
                });
            }
        }

        $indexes = collect(Schema::getIndexes($this->table))->pluck('name')->all();

        foreach ($oldIndexes as $name => $columnsForIndex) {
            if (! in_array($name, $indexes, true)) {
                Schema::table($this->table, function (Blueprint $table) use ($columnsForIndex) {
                    $table->index($columnsForIndex);
                });
            }
        }
    }
};
