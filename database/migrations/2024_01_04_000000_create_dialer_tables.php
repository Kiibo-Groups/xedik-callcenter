<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialer_dispositions', function (Blueprint $table) {
            $table->id();
            $table->string('name');            // Nombre exacto que manda el dialer
            $table->string('crm_disposition'); // Disposición que se guarda en el historial de llamada
            $table->boolean('is_active')->default(false); // Solo activas crean lead
            $table->timestamps();
        });

        DB::table('dialer_dispositions')->insert([
            ['name'=>'Test',             'crm_disposition'=>'no_answer',      'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Wrong Number',     'crm_disposition'=>'wrong_number',   'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Voice Mail',       'crm_disposition'=>'voicemail',      'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Answering Machine','crm_disposition'=>'voicemail',      'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Support',          'crm_disposition'=>'customer',       'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Sale',             'crm_disposition'=>'customer',       'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Customer',         'crm_disposition'=>'customer',       'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Do not Call',      'crm_disposition'=>'not_interested', 'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Not Available',    'crm_disposition'=>'no_answer',      'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Task',             'crm_disposition'=>'callback',       'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Callback',         'crm_disposition'=>'callback',       'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Invalid',          'crm_disposition'=>'wrong_number',   'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Appt Set',         'crm_disposition'=>'callback',       'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'No answer',        'crm_disposition'=>'no_answer',      'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Fax',              'crm_disposition'=>'wrong_number',   'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Not Interested',   'crm_disposition'=>'not_interested', 'is_active'=>0,'created_at'=>now(),'updated_at'=>now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dialer_dispositions');
    }
};
