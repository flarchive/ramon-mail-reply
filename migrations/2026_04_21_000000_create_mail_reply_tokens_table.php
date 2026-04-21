<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable(
    'mail_reply_tokens',
    function (Blueprint $table) {
        $table->string('token', 64)->primary();
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('discussion_id');
        $table->unsignedInteger('post_id')->nullable();
        $table->timestamp('created_at');
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('used_at')->nullable();

        $table->index(['user_id', 'discussion_id']);
        $table->index('expires_at');
    }
);
