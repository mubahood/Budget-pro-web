<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person, a second account beside their first: rent books stand alone (KR-16), so someone with a shop who also lets property
 * gets an account of its own. They sign in as themselves, and the app they sign in to decides which of the two opens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_siblings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('the login the person signs in with');
            $table->unsignedBigInteger('sibling_user_id')->unique()->comment('the owner login of the account beside it, never signed in to directly');
            $table->string('app', 40);
            $table->timestamps();
            $table->unique(['user_id', 'app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_siblings');
    }
};
